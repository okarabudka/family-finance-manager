<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\Category;
use App\Entity\CategoryType;
use App\Entity\User;
use App\Form\CategoryFormType;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/categories')]
class CategoryController extends AbstractController
{
    #[Route('', name: 'category_list', methods: ['GET'])]
    public function list(CategoryRepository $categoryRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $family = $user->getFamily();

        if (null === $family) {
            return $this->redirectToRoute('dashboard');
        }

        $expenseCategories = $categoryRepository->findByFamily($family, CategoryType::Expense);
        $incomeCategories = $categoryRepository->findByFamily($family, CategoryType::Income);

        return $this->render('category/list.html.twig', [
            'expenseCategories' => $expenseCategories,
            'incomeCategories' => $incomeCategories,
        ]);
    }

    #[Route('/new', name: 'category_new', methods: ['GET', 'POST'])]
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

        $category = new Category();
        $form = $this->createForm(CategoryFormType::class, $category, ['family' => $family]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $category->setFamily($family);
            $em->persist($category);
            $em->flush();

            $this->addFlash('success', 'flash.category.created');

            return $this->redirectToRoute('category_list');
        }

        return $this->render('category/form.html.twig', [
            'form' => $form,
            'title' => 'category.new',
        ]);
    }
}
