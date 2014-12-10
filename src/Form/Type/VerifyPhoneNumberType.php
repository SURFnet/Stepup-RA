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

namespace Surfnet\StepupRa\RaBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class VerifyPhoneNumberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('challenge', 'text', [
            'label' => 'ra.form.ra_verify_phone_number.text.challenge',
            'required' => true,
            'attr' => array(
                'autofocus' => true,
            )
        ]);
        $builder->add('verify-challenge', 'submit', [
            'label' => 'ra.form.ra_verify_phone_number.button.verify_challenge',
            'attr' => [ 'class' => 'btn btn-primary pull-right' ],
        ]);
        $builder->add('resend-challenge', 'anchor', [
            'label' => 'ra.form.ra_verify_phone_number.button.resend_challenge',
            'attr' => [ 'class' => 'btn btn-default' ],
            'route' => 'ra_vetting_sms_send_challenge',
            'route_parameters' => ['procedureUuid' => $options['procedureUuid']],
        ]);
    }

    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults([
            'data_class' => 'Surfnet\StepupRa\RaBundle\Command\VerifyPhoneNumberCommand',
            'procedureUuid' => null,
        ]);

        $resolver->setRequired(['procedureUuid']);

        $resolver->setAllowedTypes([
            'procedureUuid' => 'string',
        ]);
    }

    public function getName()
    {
        return 'ra_verify_phone_number';
    }
}
