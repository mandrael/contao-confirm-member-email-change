<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\MemberModel;
use Contao\StringUtil;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\CanonicalUsername;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EligibilityReason;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernamePolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A5: the only way to move a member onto "email as username" that already
 * has a DIFFERENT ("fantasy") username – the automatic sync (A3) deliberately
 * never touches those. Defaults to a dry run; never prints an email address
 * or username, only member IDs and the case class, so operators can pipe the
 * output without leaking data.
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
        $groupId = null !== $group ? (int) $group : null;

        $this->framework->initialize();

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
            $member->username = CanonicalUsername::normalize((string) $member->email);
            $member->tstamp = time();
            $member->save();

            return 'umgestellt';
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
