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

namespace Surfnet\StepupRa\RaBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Surfnet\StepupBundle\Service\SecondFactorTypeService;
use Surfnet\StepupBundle\Value\SecondFactorType;
use Surfnet\StepupRa\RaBundle\Command\StartVettingProcedureCommand;
use Surfnet\StepupRa\RaBundle\Command\VerifyIdentityCommand;
use Surfnet\StepupRa\RaBundle\Exception\DomainException;
use Surfnet\StepupRa\RaBundle\Exception\RuntimeException;
use Surfnet\StepupRa\RaBundle\Form\Type\StartVettingProcedureType;
use Surfnet\StepupRa\RaBundle\Form\Type\VerifyIdentityType;
use Surfnet\StepupRa\RaBundle\Security\Authentication\Token\SamlToken;
use Surfnet\StepupRa\RaBundle\Service\SecondFactorService;
use Surfnet\StepupRa\RaBundle\Service\VettingService;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class VettingController extends Controller
{
    /**
     * @Template
     * @param Request $request
     * @return array|Response
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) https://www.pivotaltracker.com/story/show/135045063
     * @SuppressWarnings(PHPMD.NPathComplexity)      https://www.pivotaltracker.com/story/show/135045063
     */
    public function startProcedureAction(Request $request)
    {
        $this->denyAccessUnlessGranted(['ROLE_RA']);
        $logger = $this->get('logger');
        $identity = $this->getIdentity();

        $logger->notice('Vetting Procedure Search started');

        $command = new StartVettingProcedureCommand();

        $form = $this->createForm(StartVettingProcedureType::class, $command)->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $logger->notice('No search submitted, displaying search by registration code form');

            return ['form' => $form->createView()];
        }

        $secondFactor = $this->getSecondFactorService()
            ->findVerifiedSecondFactorByRegistrationCode($command->registrationCode, $identity->id);

        if ($secondFactor === null) {
            $this->addFlash('error', 'ra.form.start_vetting_procedure.unknown_registration_code');
            $logger->notice('Cannot start new vetting procedure, no second factor found');

            return ['form' => $form->createView()];
        }

        $enabledSecondFactors = $this->container->getParameter('surfnet_stepup_ra.enabled_second_factors');
        if (!in_array($secondFactor->type, $enabledSecondFactors, true)) {
            $logger->warning(
                sprintf(
                    'An RA attempted vetting of disabled second factor "%s" of type "%s"',
                    $secondFactor->id,
                    $secondFactor->type
                )
            );

            return $this
                ->render(
                    'SurfnetStepupRaRaBundle:Vetting:secondFactorTypeDisabled.html.twig',
                    ['secondFactorType' => $secondFactor->type]
                )
                ->setStatusCode(Response::HTTP_BAD_REQUEST);
        }

        /** @var SamlToken $token */
        $token = $this->get('security.token_storage')->getToken();
        $command->authorityId = $this->getIdentity()->id;
        $command->authorityLoa = $token->getLoa();
        $command->secondFactor = $secondFactor;

        if ($this->getVettingService()->isExpiredRegistrationCode($command)) {
            $this->addFlash(
                'error',
                $this->getTranslator()
                    ->trans(
                        'ra.verify_identity.registration_code_expired',
                        [
                            '%self_service_url%' => $this->getParameter('surfnet_stepup_ra.self_service_url'),
                        ]
                    )
            );

            $logger->notice(
                'Second factor registration code is expired',
                ['registration_requested_at' => $secondFactor->registrationRequestedAt->format('Y-m-d')]
            );

            return ['form' => $form->createView()];
        }

        if (!$this->getVettingService()->isLoaSufficientToStartProcedure($command)) {
            $this->addFlash('error', 'ra.form.start_vetting_procedure.loa_insufficient');

            $logger->notice('Cannot start new vetting procedure, Authority LoA is insufficient');

            return ['form' => $form->createView()];
        }

        $procedureId = $this->getVettingService()->startProcedure($command);

        $this->get('ra.procedure_logger')
            ->forProcedure($procedureId)
            ->notice(sprintf('Starting new Vetting Procedure for second factor of type "%s"', $secondFactor->type));

        $secondFactorType = new SecondFactorType($secondFactor->type);
        if ($secondFactorType->isYubikey()) {
            return $this->redirectToRoute('ra_vetting_yubikey_verify', ['procedureId' => $procedureId]);
        } elseif ($secondFactorType->isSms()) {
            return $this->redirectToRoute('ra_vetting_sms_send_challenge', ['procedureId' => $procedureId]);
        } elseif ($this->getSecondFactorTypeService()->isGssf($secondFactorType)) {
            return $this->redirectToRoute(
                'ra_vetting_gssf_initiate',
                [
                    'procedureId' => $procedureId,
                    'provider'    => $secondFactor->type
                ]
            );
        } else {
            throw new RuntimeException(
                sprintf('RA does not support vetting procedure for second factor type "%s"', $secondFactor->type)
            );
        }
    }

    public function cancelProcedureAction($procedureId)
    {
        $logger = $this->get('ra.procedure_logger')->forProcedure($procedureId);

        if (!$this->getVettingService()->hasProcedure($procedureId)) {
            $logger->notice(sprintf('Vetting procedure "%s" not found', $procedureId));
            throw new NotFoundHttpException(sprintf('Vetting procedure "%s" not found', $procedureId));
        }

        $this->getVettingService()->cancelProcedure($procedureId);
        $this->addFlash('info', $this->get('translator')->trans('ra.vetting.flash.cancelled'));

        return $this->redirectToRoute('ra_vetting_search');
    }

    /**
     * @Template
     * @param Request $request
     * @param string $procedureId
     * @return array|Response
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function verifyIdentityAction(Request $request, $procedureId)
    {
        $this->denyAccessUnlessGranted(['ROLE_RA']);

        $logger = $this->get('ra.procedure_logger')->forProcedure($procedureId);
        $logger->notice('Verify Identity Form requested');

        if (!$this->getVettingService()->hasProcedure($procedureId)) {
            $logger->notice(sprintf('Vetting procedure "%s" not found', $procedureId));
            throw new NotFoundHttpException(sprintf('Vetting procedure "%s" not found', $procedureId));
        }

        $command = new VerifyIdentityCommand();
        $form = $this->createForm(VerifyIdentityType::class, $command)->handleRequest($request);

        /** @var SubmitButton $cancelButton */
        $cancelButton = $form->get('cancel');
        if ($cancelButton->isClicked()) {
            $this->getVettingService()->cancelProcedure($procedureId);
            $this->addFlash('info', $this->get('translator')->trans('ra.vetting.flash.cancelled'));

            return $this->redirectToRoute('ra_vetting_search');
        }

        $vettingService = $this->getVettingService();
        $commonName = $vettingService->getIdentityCommonName($procedureId);

        $showForm = function ($error = null) use ($form, $commonName) {
            if ($error) {
                $this->addFlash('error', $error);
            }

            return ['commonName' => $commonName, 'form' => $form->createView()];
        };

        if (!$form->isSubmitted() || !$form->isValid()) {
            $logger->notice('Verify Identity Form not submitted, displaying form');

            return $showForm();
        }

        try {
            $vettingService->verifyIdentity($procedureId, $command);
        } catch (DomainException $e) {
            $this->get('logger')->error(
                "RA attempted to verify identity, but the vetting procedure does not allow it",
                ['exception' => $e, 'procedure' => $procedureId]
            );

            return $showForm('ra.verify_identity.identity_verification_failed');
        }

        try {
            $vetting = $vettingService->vet($procedureId);
            if ($vetting->isSuccessful()) {
                $logger->notice('Identity Verified, vetting completed');

                return $this->redirectToRoute('ra_vetting_completed', ['procedureId' => $procedureId]);
            }

            $logger->error('RA attempted to vet second factor, but the command failed');

            if (in_array(VettingService::REGISTRATION_CODE_EXPIRED_ERROR, $vetting->getErrors())) {
                $registrationCodeExpiredError = $this->getTranslator()
                    ->trans(
                        'ra.verify_identity.registration_code_expired',
                        [
                            '%self_service_url%' => $this->getParameter('surfnet_stepup_ra.self_service_url'),
                        ]
                    );

                return $showForm($registrationCodeExpiredError);
            }

            return $showForm('ra.verify_identity.second_factor_vetting_failed');
        } catch (DomainException $e) {
            $logger->error(
                "RA attempted to vet second factor, but the vetting procedure didn't allow it",
                ['exception' => $e]
            );

            return $showForm('ra.verify_identity.second_factor_vetting_failed');
        }
    }

    /**
     * @Template
     */
    public function vettingCompletedAction()
    {
        return [];
    }

    /**
     * @return SecondFactorService
     */
    private function getSecondFactorService()
    {
        return $this->get('ra.service.second_factor');
    }

    /**
     * @return SecondFactorTypeService
     */
    private function getSecondFactorTypeService()
    {
        return $this->get('surfnet_stepup.service.second_factor_type');
    }

    /**
     * @return VettingService
     */
    private function getVettingService()
    {
        return $this->get('ra.service.vetting');
    }

    /**
     * @return \Surfnet\StepupMiddlewareClientBundle\Identity\Dto\Identity
     */
    private function getIdentity()
    {
        return $this->get('security.token_storage')->getToken()->getUser();
    }

    /**
     * @return TranslatorInterface
     */
    private function getTranslator()
    {
        return $this->get('translator');
    }
}
