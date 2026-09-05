<?php

declare(strict_types=1);

namespace Linderp\SuluIndexNowBundle\Tests\DependencyInjection;

use Linderp\SuluIndexNowBundle\DependencyInjection\SuluIndexNowExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

final class SuluIndexNowExtensionTest extends TestCase
{
    public function testItRegistersItsEntityMappingSoTheHistoryTableCanBeCreated(): void
    {
        $container = $this->createContainerWithDoctrine();

        (new SuluIndexNowExtension())->prepend($container);

        $config = $container->getExtensionConfig('doctrine');
        $mapping = $config[0]['orm']['mappings']['SuluIndexNowBundle'];

        self::assertSame('xml', $mapping['type']);
        self::assertSame('Linderp\SuluIndexNowBundle\Entity', $mapping['prefix']);
        self::assertFalse($mapping['is_bundle']);
        self::assertDirectoryExists($mapping['dir']);
        self::assertFileExists($mapping['dir'] . '/IndexNowSubmission.orm.xml');
    }

    public function testItStaysSilentWhenDoctrineIsNotInstalled(): void
    {
        $container = new ContainerBuilder();

        (new SuluIndexNowExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    public function testItExposesTheConfiguredKeyAndSearchEnginesToTheServices(): void
    {
        $container = new ContainerBuilder();

        (new SuluIndexNowExtension())->load([[
            'key' => 'secret',
            'search_engines' => ['Bing' => 'https://www.bing.com/indexnow'],
        ]], $container);

        self::assertSame('secret', $container->getParameter('sulu_index_now.key'));
        self::assertSame(
            ['Bing' => 'https://www.bing.com/indexnow'],
            $container->getParameter('sulu_index_now.search_engines'),
        );
    }

    private function createContainerWithDoctrine(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class() implements ExtensionInterface {
            public function load(array $configs, ContainerBuilder $container): void {}

            public function getNamespace(): string
            {
                return '';
            }

            public function getXsdValidationBasePath(): string|false
            {
                return false;
            }

            public function getAlias(): string
            {
                return 'doctrine';
            }
        });

        return $container;
    }
}
