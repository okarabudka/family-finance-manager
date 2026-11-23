<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Domain\Account\AccountService;
use App\Entity\Account;
use App\Entity\User;
use App\Form\AccountFormType;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/accounts')]
class AccountController extends AbstractController
{
    #[Route('', name: 'account_list', methods: ['GET'])]
    public function list(
        AccountRepository $accountRepository,
        AccountService $accountService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $family = $user->getFamily();

        if (null === $family) {
            return $this->redirectToRoute('dashboard');
        }

        $accounts = $accountRepository->findActiveByFamily($family);

        $balances = [];
        foreach ($accounts as $account) {
            $balances[(string) $account->getId()] = $accountService->getBalance($account);
        }

        return $this->render('account/list.html.twig', [
            'accounts' => $accounts,
            'balances' => $balances,
        ]);
    }

    #[Route('/new', name: 'account_new', methods: ['GET', 'POST'])]
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

        $account = new Account();
        $form = $this->createForm(AccountFormType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $account->setFamily($family);
            $em->persist($account);
            $em->flush();

            $this->addFlash('success', 'flash.account.created');

            return $this->redirectToRoute('account_list');
        }

        return $this->render('account/form.html.twig', [
            'form' => $form,
            'title' => 'account.new',
        ]);
    }

    #[Route('/{id}/edit', name: 'account_edit', methods: ['GET', 'POST'])]
    public function edit(
        Account $account,
        Request $request,
        EntityManagerInterface $em,
        AccountService $accountService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $family = $user->getFamily();

        if (null === $family || !$account->getFamily()->getId()->equals($family->getId())) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(AccountFormType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $accountService->invalidateCache($account);

            $this->addFlash('success', 'flash.account.updated');

            return $this->redirectToRoute('account_list');
        }

        return $this->render('account/form.html.twig', [
            'form' => $form,
            'title' => 'account.edit',
            'account' => $account,
        ]);
    }
}
