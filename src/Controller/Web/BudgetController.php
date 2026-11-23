<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\Budget;
use App\Entity\User;
use App\Form\BudgetFormType;
use App\Repository\BudgetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/budgets')]
class BudgetController extends AbstractController
{
    #[Route('', name: 'budget_list', methods: ['GET'])]
    public function list(
        Request $request,
        BudgetRepository $budgetRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $family = $user->getFamily();

        if (null === $family) {
            return $this->redirectToRoute('dashboard');
        }

        $year = $request->query->getInt('year', (int) date('Y'));
        $month = $request->query->getInt('month', (int) date('n'));

        $budgets = $budgetRepository->findBy(
            ['family' => $family, 'year' => $year, 'month' => $month],
            ['month' => 'DESC'],
        );

        return $this->render('budget/list.html.twig', [
            'budgets' => $budgets,
            'year' => $year,
            'month' => $month,
        ]);
    }

    #[Route('/new', name: 'budget_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $family = $user->getFamily();

        if (null === $family) {
            return $this->redirectToRoute('dashboard');
        }

        $budget = new Budget();
        $form = $this->createForm(BudgetFormType::class, $budget, ['family' => $family]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $budget->setFamily($family);
            $em->persist($budget);
            $em->flush();

            $this->addFlash('success', 'flash.budget.created');

            return $this->redirectToRoute('budget_list');
        }

        return $this->render('budget/form.html.twig', [
            'form' => $form,
            'title' => 'budget.new',
        ]);
    }

    #[Route('/{id}/edit', name: 'budget_edit', methods: ['GET', 'POST'])]
    public function edit(
        Budget $budget,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $family = $user->getFamily();

        if (null === $family || !$budget->getFamily()->getId()->equals($family->getId())) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(BudgetFormType::class, $budget, ['family' => $family]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash('success', 'flash.budget.updated');

            return $this->redirectToRoute('budget_list');
        }

        return $this->render('budget/form.html.twig', [
            'form' => $form,
            'title' => 'budget.edit',
            'budget' => $budget,
        ]);
    }

    #[Route('/{id}/delete', name: 'budget_delete', methods: ['POST'])]
    public function delete(
        Budget $budget,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $family = $user->getFamily();

        if (null === $family || !$budget->getFamily()->getId()->equals($family->getId())) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('delete_budget_'.$budget->getId(), $request->request->getString('_token'))) {
            $em->remove($budget);
            $em->flush();
            $this->addFlash('success', 'flash.budget.deleted');
        } else {
            $this->addFlash('error', 'flash.invalid_csrf');
        }

        return $this->redirectToRoute('budget_list');
    }
}
