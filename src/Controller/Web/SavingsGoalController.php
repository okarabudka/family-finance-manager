<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\SavingsGoal;
use App\Entity\User;
use App\Form\SavingsGoalFormType;
use App\Repository\SavingsGoalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/savings-goals')]
class SavingsGoalController extends AbstractController
{
    #[Route('', name: 'savings_goal_list', methods: ['GET'])]
    public function list(
        SavingsGoalRepository $savingsGoalRepository,
    ): Response {
        /** @var User $user */
        $user   = $this->getUser();
        $family = $user->getFamily();

        if ($family === null) {
            return $this->redirectToRoute('dashboard');
        }

        $goals = $savingsGoalRepository->findByFamily($family);

        return $this->render('savings_goal/list.html.twig', [
            'goals' => $goals,
        ]);
    }

    #[Route('/new', name: 'savings_goal_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user   = $this->getUser();
        $family = $user->getFamily();

        if ($family === null) {
            return $this->redirectToRoute('dashboard');
        }

        $goal = new SavingsGoal();
        $form = $this->createForm(SavingsGoalFormType::class, $goal, ['family' => $family]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $goal->setFamily($family);
            $em->persist($goal);
            $em->flush();

            $this->addFlash('success', 'flash.savings_goal.created');

            return $this->redirectToRoute('savings_goal_list');
        }

        return $this->render('savings_goal/form.html.twig', [
            'form'  => $form,
            'title' => 'savings_goal.new',
        ]);
    }

    #[Route('/{id}/edit', name: 'savings_goal_edit', methods: ['GET', 'POST'])]
    public function edit(
        SavingsGoal $goal,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user   = $this->getUser();
        $family = $user->getFamily();

        if ($family === null || !$goal->getFamily()->getId()->equals($family->getId())) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(SavingsGoalFormType::class, $goal, ['family' => $family]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash('success', 'flash.savings_goal.updated');

            return $this->redirectToRoute('savings_goal_list');
        }

        return $this->render('savings_goal/form.html.twig', [
            'form'  => $form,
            'title' => 'savings_goal.edit',
            'goal'  => $goal,
        ]);
    }

    #[Route('/{id}/delete', name: 'savings_goal_delete', methods: ['POST'])]
    public function delete(
        SavingsGoal $goal,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user   = $this->getUser();
        $family = $user->getFamily();

        if ($family === null || !$goal->getFamily()->getId()->equals($family->getId())) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('delete_savings_goal_' . $goal->getId(), $request->request->get('_token'))) {
            $em->remove($goal);
            $em->flush();
            $this->addFlash('success', 'flash.savings_goal.deleted');
        } else {
            $this->addFlash('error', 'flash.invalid_csrf');
        }

        return $this->redirectToRoute('savings_goal_list');
    }
}
