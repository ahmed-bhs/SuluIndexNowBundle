<?php

declare(strict_types=1);

namespace Linderp\SuluIndexNowBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

class SuluIndexNowExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $config = $this->processConfiguration(new Configuration(), $configs);
        $container->setParameter(
            'sulu_index_now.key',
            $config['key']
        );
        $container->setParameter(
            'sulu_index_now.search_engines',
            $config['search_engines']
        );
        $loader->load('services.yaml');

    }

    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'SuluIndexNowBundle' => [
                        'type' => 'xml',
                        'dir' => __DIR__ . '/../Resources/config/doctrine',
                        'prefix' => 'Linderp\SuluIndexNowBundle\Entity',
                        'alias' => 'SuluIndexNow',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);
    }
}
