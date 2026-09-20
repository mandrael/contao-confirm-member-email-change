<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\MemberModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\CanonicalUsername;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EligibilityReason;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EmailAsUsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernamePolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A5: bulk-fixes every member whose username does not yet match their email
 * (typically pre-existing "fantasy" usernames from before the switch was
 * turned on) in one pass, instead of waiting for each member's next save to
 * trigger the automatic sync (A3). Defaults to a dry run; never prints an
 * email address or username, only member IDs and the case class, so
 * operators can pipe the output without leaking data.
 */
#[AsCommand(
    name: 'member-email:sync-usernames',
    description: 'Synchronizes tl_member.username with tl_member.email for the "email as username" opt-in (A2/A3). Dry run by default.',
)]
final class SyncUsernamesCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly UsernamePolicy $usernamePolicy,
        private readonly EmailAsUsernamePolicy $policy,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually write the changes (default: dry run only).')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Limit to members in this tl_member_group ID.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $group = $input->getOption('group');
        // A typo must not look like "nothing to do": (int) 'abc' is 0 and matched no member.
        if (null !== $group && (!\is_string($group) || !ctype_digit($group) || (int) $group < 1)) {
            $io->error('--group erwartet die numerische ID einer Mitgliedergruppe.');

            return Command::FAILURE;
        }

        $groupId = null !== $group ? (int) $group : null;

        $this->framework->initialize();

        // The command is the migration path FOR the opt-in, not a way around it: with
        // the switch off, writing every login name would silently change 1.0 behaviour
        // and there is no command to undo it. The dry run stays available either way,
        // so an operator can see what turning the switch on would do.
        if ($force && !$this->policy->isEnabled()) {
            $io->error('Der Schalter "memberEmailAsUsername" ist ausgeschaltet. --force schreibt nur bei eingeschaltetem Schalter; der Probelauf ohne --force ist jederzeit möglich.');

            return Command::FAILURE;
        }

        $members = $this->framework->getAdapter(MemberModel::class)->findAll();
        $rows = [];
        $counts = [];

        foreach ($members ?? [] as $member) {
            if (null !== $groupId && !$this->inGroup($member, $groupId)) {
                continue;
            }

            $label = $this->classify($member, $force);
            $counts[$label] = ($counts[$label] ?? 0) + 1;
            $rows[] = [$member->id, $label];
        }

        $io->table(['ID', 'Fall'], $rows);

        $io->section('Summen');

        foreach ($counts as $label => $count) {
            $io->writeln(\sprintf('%s: %d', $label, $count));
        }

        if (!$force) {
            $io->note('Probelauf, es wurde nichts geschrieben. Mit --force ausführen, um zu schreiben. Vorher ein Datenbank-Backup anlegen.');
        }

        return Command::SUCCESS;
    }

    private function classify(MemberModel $member, bool $force): string
    {
        $reason = $this->usernamePolicy->evaluate((string) $member->email, (int) $member->id);

        $label = match ($reason) {
            EligibilityReason::EmailEmpty => 'E-Mail leer',
            EligibilityReason::TooLong => 'zu lang',
            EligibilityReason::Invalid => 'ungültig',
            EligibilityReason::Collision => 'Kollision',
            EligibilityReason::Eligible => $this->classifyEligible($member),
        };

        if ('würde umgestellt' === $label && $force) {
            // Conditional on the address findAll() read
            // above, same as UsernameSyncListener::onSubmitMember() - not Model::save(),
            // which would write unconditionally and could overwrite a login name a
            // racing confirm/revoke had just set from a different address. Zero affected
            // rows means exactly that happened in the meantime; the next run catches it.
            // Same BINARY fix as UsernameSyncListener - see
            // there for why utf8mb4_unicode_ci is not byte-exact.
            $email = (string) $member->email;
            $affected = $this->connection->executeStatement(
                'UPDATE tl_member SET username = ?, tstamp = ? WHERE id = ? AND BINARY email = ?',
                [CanonicalUsername::normalize($email), time(), (int) $member->id, $email],
            );

            return $affected > 0 ? 'umgestellt' : 'übersprungen (Adresse zwischenzeitlich geändert)';
        }

        return $label;
    }

    private function classifyEligible(MemberModel $member): string
    {
        $canonical = CanonicalUsername::normalize((string) $member->email);

        return $canonical === $member->username ? 'unverändert' : 'würde umgestellt';
    }

    private function inGroup(MemberModel $member, int $groupId): bool
    {
        $groups = $this->framework->getAdapter(StringUtil::class)->deserialize($member->groups, true);

        return \in_array($groupId, array_map('intval', $groups), true);
    }
}
