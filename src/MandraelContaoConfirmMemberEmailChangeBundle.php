<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class MandraelContaoConfirmMemberEmailChangeBundle extends AbstractBundle
{
    public function getPath(): string
    {
        // Resources live at the repository root (contao/, config/), not under src/Resources/.
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->integerNode('jump_to')
                    ->info('Optional page ID to redirect to after a confirmation attempt (the success/error message is shown there). Defaults to the site root.')
                    ->defaultNull()
                ->end()
            ->end()
        ;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.yaml');

        $builder->setParameter(
            'mandrael_contao_confirm_member_email_change.jump_to',
            $config['jump_to'] ?? null,
        );
    }
}
