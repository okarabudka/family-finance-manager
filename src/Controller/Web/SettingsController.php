<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\User;
use App\Form\FamilySettingsFormType;
use App\Form\NewUserFormType;
use App\Form\ProfileFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[IsGranted('ROLE_USER')]
#[Route('/settings')]
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    #[Route('', name: 'settings', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $family = $user->getFamily();

        // --- Profile form ---
        $profileForm = $this->createForm(ProfileFormType::class, $user);
        $profileForm->handleRequest($request);

        if ($profileForm->isSubmitted() && $profileForm->isValid()) {
            $newPassword = $profileForm->get('newPassword')->getData();

            if ($newPassword !== null && $newPassword !== '') {
                $currentPassword = $profileForm->get('currentPassword')->getData() ?? '';

                if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
                    $this->addFlash('error', 'flash.settings.wrong_password');

                    return $this->redirectToRoute('settings');
                }

                $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            }

            $this->em->flush();
            $this->addFlash('success', 'flash.settings.profile_updated');

            return $this->redirectToRoute('settings');
        }

        // --- Family form ---
        $familyForm = null;

        if ($family !== null) {
            $familyForm = $this->createForm(FamilySettingsFormType::class, $family);
            $familyForm->handleRequest($request);

            if ($familyForm->isSubmitted() && $familyForm->isValid()) {
                $this->em->flush();
                $this->addFlash('success', 'flash.settings.family_updated');

                return $this->redirectToRoute('settings');
            }
        }

        return $this->render('settings/index.html.twig', [
            'profileForm' => $profileForm,
            'familyForm'  => $familyForm,
            'user'        => $user,
            'family'      => $family,
        ]);
    }

    #[Route('/users/new', name: 'settings_users_new', methods: ['GET', 'POST'])]
    public function newUser(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $family      = $currentUser->getFamily();

        if ($family === null) {
            $this->addFlash('error', 'flash.settings.no_family');

            return $this->redirectToRoute('settings');
        }

        $form = $this->createForm(NewUserFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $existingUser = $this->em->getRepository(User::class)->findOneBy(['email' => $data['email']]);
            if ($existingUser !== null) {
                $this->addFlash('error', 'flash.settings.email_taken');

                return $this->redirectToRoute('settings_users_new');
            }

            $role     = $form->get('roles')->getData();
            $password = $form->get('password')->getData();

            $newUser = new User();
            $newUser->setName($data['name']);
            $newUser->setEmail($data['email']);
            $newUser->setPassword($this->passwordHasher->hashPassword($newUser, $password));
            $newUser->setRoles([$role]);
            $newUser->setFamily($family);

            $this->em->persist($newUser);
            $this->em->flush();

            $this->addFlash('success', new TranslatableMessage('flash.settings.user_created', ['%name%' => $newUser->getName()]));

            return $this->redirectToRoute('settings');
        }

        return $this->render('settings/new_user.html.twig', [
            'form' => $form,
        ]);
    }
}
