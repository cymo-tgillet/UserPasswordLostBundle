<?php

namespace inem0o\UserPasswordLostBundle\Controller;

use AppBundle\AppBundle;
use inem0o\UserPasswordLostBundle\Entity\PasswordResetRequest;
use inem0o\UserPasswordLostBundle\Entity\PasswordResetRequestIdentity;
use inem0o\UserPasswordLostBundle\Form\PasswordResetRequestType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\Translator;

class CreatePasswordResetRequestController extends Controller
{
    public function indexAction(Request $request)
    {
        /** @var Translator $translator */
        $translator = $this->get('translator.default');

        $user_repo_name         = $this->getParameter("user_password_lost.user_repo_name");
        $user_email_column_name = $this->getParameter("user_password_lost.user_email_column_name");
        $email_from             = $this->getParameter("user_password_lost.email_from");

        $doctrine = $this->getDoctrine();
        $manager  = $doctrine->getManager();

        $user_repo = $doctrine->getRepository($user_repo_name);

        $reset_request_repo = $doctrine->getRepository("UserPasswordLostBundle:PasswordResetRequest");
        $reset_request      = new PasswordResetRequest();

        $request_create_form = $this->createForm(PasswordResetRequestType::class, $reset_request);
        if ($request_create_form->handleRequest($request)->isValid()) {
            $email = $reset_request->getUserEmail();

            $user = $user_repo->findOneBy([$user_email_column_name => $email]);

            // check if user exists
            if (null !== $user) {
                // check if user already have pending reset request to disable
                $pending_requests = $reset_request_repo->findBy(
                    [
                        'user_email' => $email,
                        'status'     => PasswordResetRequest::STATUS_PENDING,
                    ]
                );
                foreach ($pending_requests as $pending_request) {
                    $pending_request->setStatus(PasswordResetRequest::STATUS_EXPIRED);
                    $pending_request->setDateEnd(new \DateTime("now"));
                    $manager->persist($pending_request);
                }
                $manager->flush();

                // create pending request
                $found_used_token = null;
                do {
                    $token            = bin2hex(random_bytes(32));
                    $found_used_token = $reset_request_repo->findOneBy(['token' => $token]);
                } while (null !== $found_used_token);

                $pending_request = new PasswordResetRequest();
                $pending_request->setDateAdd(new \DateTime("now"));
                $pending_request->setStatus(PasswordResetRequest::STATUS_PENDING);
                $pending_request->setUserEmail($email);
                $pending_request->setToken($token);

                $identity = PasswordResetRequestIdentity::factoryFromRequest($request);
                $identity->setRequestStatus($pending_request->getStatus());
                $identity->setPasswordResetRequest($pending_request);

                $manager->persist($identity);
                $manager->persist($pending_request);
                $manager->flush();

                // sending email
                $email_subject = $translator->trans('user_password_lost_bundle.email.subject', [], 'userPasswordLostBundle');
                $message       = \Swift_Message::newInstance()
                    ->setSubject($email_subject)
                    ->setFrom($email_from)
                    ->setTo($email)
                    ->setBody(
                        $this->renderView(
                            'UserPasswordLostBundle:email:password_reset_request.html.twig',
                            array('password_reset_request' => $pending_request)
                        ),
                        'text/html'
                    );
                $this->get('mailer')->send($message);
            } else {
                sleep(2);   // fake email
            }

            return $this->redirectToRoute("confirm_password_reset_request");
        }

        return $this->render(
            'UserPasswordLostBundle:create_password_reset_request:index.html.twig',
            array(
                'form_password_reset_request' => $request_create_form->createView(),
            )
        );
    }

    public function confirmAction(Request $request)
    {
        return $this->render(
            'UserPasswordLostBundle:create_password_reset_request:confirm.html.twig',
            array()
        );
    }
}