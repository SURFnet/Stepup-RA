<?php

/**
 * Copyright 2014 SURFnet bv
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Surfnet\StepupRa\SamlStepupProviderBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class SurfnetStepupRaSamlStepupProviderExtension extends Extension
{

    const VIEW_CONFIG_TAG_NAME = 'gssp.view_config';

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');

        foreach ($config['providers'] as $provider => $providerConfiguration) {
            // may seem a bit strange, but this prevents casing issue when getting/setting/creating provider
            // service definitions etc.
            if ($provider !== strtolower($provider)) {
                throw new InvalidConfigurationException('The provider name must be completely lowercase');
            }

            $this->loadProviderConfiguration($provider, $providerConfiguration, $config['routes'], $container);
        }
    }

    private function loadProviderConfiguration(
        $provider,
        array $configuration,
        array $routes,
        ContainerBuilder $container
    ) {
        if ($container->has('gssp.provider.' . $provider)) {
            throw new InvalidConfigurationException(sprintf('Cannot create the same provider "%s" twice', $provider));
        }

        $this->createHostedDefinitions($provider, $configuration['hosted'], $routes, $container);
        $this->createMetadataDefinition($provider, $configuration['hosted'], $routes, $container);
        $this->createRemoteDefinition($provider, $configuration['remote'], $container);

        $stateHandlerDefinition = new Definition('Surfnet\StepupRa\SamlStepupProviderBundle\Saml\StateHandler', [
            new Reference('gssp.sessionbag'),
            $provider
        ]);
        $container->setDefinition('gssp.provider.' . $provider . '.statehandler', $stateHandlerDefinition);

        $providerDefinition = new Definition('Surfnet\StepupRa\SamlStepupProviderBundle\Provider\Provider', [
            $provider,
            new Reference('gssp.provider.' . $provider . '.hosted.sp'),
            new Reference('gssp.provider.' . $provider . '.remote.idp'),
            new Reference('gssp.provider.' . $provider . '.statehandler')
        ]);

        $providerDefinition->setPublic(false);
        $container->setDefinition('gssp.provider.' . $provider, $providerDefinition);

        $viewConfigDefinition = new Definition('Surfnet\StepupRa\SamlStepupProviderBundle\Provider\ViewConfig', [
            new Reference('request_stack'),
            $configuration['view_config']['title'],
            $configuration['view_config']['page_title'],
            $configuration['view_config']['explanation'],
            $configuration['view_config']['initiate'],
            $configuration['view_config']['gssf_id_mismatch'],
        ]);
        $viewConfigDefinition->setPublic(false);
        $viewConfigDefinition->addTag(self::VIEW_CONFIG_TAG_NAME);

        $container->setDefinition('gssp.view_config.' . $provider, $viewConfigDefinition);

        $container
            ->getDefinition('gssp.provider_repository')
            ->addMethodCall('addProvider', [new Reference('gssp.provider.' . $provider)]);
    }

    /**
     * @param string           $provider
     * @param array            $configuration
     * @param array            $routes
     * @param ContainerBuilder $container
     */
    private function createHostedDefinitions(
        $provider,
        array $configuration,
        array $routes,
        ContainerBuilder $container
    ) {
        $hostedDefinition = $this->buildHostedEntityDefinition($provider, $configuration, $routes);
        $container->setDefinition('gssp.provider.' . $provider . '.hosted_entities', $hostedDefinition);

        $hostedSpDefinition  = (new Definition())
            ->setClass('Surfnet\SamlBundle\Entity\ServiceProvider')
            ->setFactory([
                new Reference('gssp.provider.' . $provider . '.hosted_entities'),
                'getServiceProvider'
            ])
            ->setPublic(false);
        $container->setDefinition('gssp.provider.' . $provider . '.hosted.sp', $hostedSpDefinition);
    }

    /**
     * @param string $provider
     * @param array  $configuration
     * @param array  $routes
     * @return Definition
     */
    private function buildHostedEntityDefinition($provider, array $configuration, array $routes)
    {
        $entityId = ['entity_id_route' => $this->createRouteConfig($provider, $routes['metadata'])];
        $spAdditional = [
            'enabled' => true,
            'assertion_consumer_route' => $this->createRouteConfig($provider, $routes['consume_assertion'])
        ];
        $idpAdditional = [
            'enabled' => false,
        ];

        $serviceProvider  = array_merge($configuration['service_provider'], $spAdditional, $entityId);
        $identityProvider = array_merge($idpAdditional, $entityId);

        $hostedDefinition = new Definition('Surfnet\SamlBundle\Entity\HostedEntities', [
            new Reference('router'),
            new Reference('request_stack'),
            $serviceProvider,
            $identityProvider
        ]);

        $hostedDefinition->setPublic(false);

        return $hostedDefinition;
    }

    /**
     * @param string           $provider
     * @param array            $configuration
     * @param ContainerBuilder $container
     */
    private function createRemoteDefinition($provider, array $configuration, ContainerBuilder $container)
    {
        $definition    = new Definition('Surfnet\SamlBundle\Entity\IdentityProvider', [
            [
                'entityId'        => $configuration['entity_id'],
                'ssoUrl'          => $configuration['sso_url'],
                'certificateData' => $configuration['certificate'],
            ]
        ]);

        $definition->setPublic(false);
        $container->setDefinition('gssp.provider.' . $provider . '.remote.idp', $definition);
    }

    /**
     * @param string           $provider
     * @param array            $configuration
     * @param array            $routes
     * @param ContainerBuilder $container
     * @return Definition
     */
    private function createMetadataDefinition(
        $provider,
        array $configuration,
        array $routes,
        ContainerBuilder $container
    ) {
        $metadataConfiguration = new Definition('Surfnet\SamlBundle\Metadata\MetadataConfiguration');

        $propertyMap = [
            'entityIdRoute'          => $this->createRouteConfig($provider, $routes['metadata']),
            'isSp'                   => true,
            'assertionConsumerRoute' => $this->createRouteConfig($provider, $routes['consume_assertion']),
            'isIdP'                  => false,
            'publicKey'              => $configuration['metadata']['public_key'],
            'privateKey'             => $configuration['metadata']['private_key'],
        ];

        $metadataConfiguration->setProperties($propertyMap);
        $metadataConfiguration->setPublic(false);
        $container->setDefinition('gssp.provider.' . $provider . 'metadata.configuration', $metadataConfiguration);

        $metadataFactory = new Definition('Surfnet\SamlBundle\Metadata\MetadataFactory', [
            new Reference('templating'),
            new Reference('router'),
            new Reference('surfnet_saml.signing_service'),
            new Reference('gssp.provider.' . $provider . 'metadata.configuration')
        ]);
        $metadataFactory->setPublic(true);
        $container->setDefinition('gssp.provider.' . $provider . '.metadata.factory', $metadataFactory);
    }

    private function createRouteConfig($provider, $routeName)
    {
        // In the future, we ought to wrap this in an object.
        // https://www.pivotaltracker.com/story/show/90095392
        return [
            'route'      => $routeName,
            'parameters' => ['provider' => $provider]
        ];
    }
}
