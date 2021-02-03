<?php

/**
 * Copyright 2019 SURFnet B.V.
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

namespace Surfnet\StepupRa\RaBundle\Controller;

use Surfnet\StepupRa\RaBundle\Service\ProfileService;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;

final class ProfileController extends Controller
{
    public function profileAction(): Response
    {
        $logger = $this->get('logger');

        $logger->notice('Opening profile page');

        $identity = $this->getUser();
        $profile = $this->getProfileService()->findByIdentityId($identity->id);
        return $this->render('SurfnetStepupRaRaBundle:profile:profile.html.twig', ['profile' => $profile]);
    }

    /**
     * @return ProfileService
     */
    private function getProfileService(): ProfileService
    {
        return $this->get('ra.service.profile');
    }
}
