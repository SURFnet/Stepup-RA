<?php

/**
 * Copyright 2018 SURFnet B.V.
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

namespace Surfnet\StepupRa\RaBundle\Service;

use Psr\Log\LoggerInterface;
use Surfnet\StepupMiddlewareClient\Identity\Dto\RaListingSearchQuery;
use Surfnet\StepupMiddlewareClientBundle\Identity\Service\RaListingService as MiddlewareClientBundleRaListingService;
use Symfony\Component\Form\ChoiceList\ArrayChoiceList;

class RaListingService
{
    /**
     * @var MiddlewareClientBundleRaListingService
     */
    private $raListingService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        MiddlewareClientBundleRaListingService $raListingService,
        LoggerInterface $logger
    ) {
        $this->raListingService = $raListingService;
        $this->logger = $logger;
    }

    /**
     * @param $institution
     * @return ArrayChoiceList
     */
    public function createChoiceListFor($institution)
    {
        $query = new RaListingSearchQuery($institution, 1);
        $query->setIdentityId($institution->getIdentityInstitution());

        $collection = $this->raListingService->search($query);

        if ($collection->getTotalItems() === 0) {
            $this->logger->warning('No RAA institutions found for identity, unable to build the choice list for the RAA switcher');
            return new ArrayChoiceList();
        }

        $choices = [];
        foreach ($collection as $item) {
            $choices[$item->institution] = $item->institution;
        }

        return new ArrayChoiceList($choices);
    }
}
