<?php

namespace App\Controller\Space;

use App\Form\Space\CompanyType;
use App\Upload\InvalidLogoException;
use App\Upload\LogoStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * La fiche de l'entreprise et sa page publique : nom, lien, présentation, adresse, couleur, logo, fuseau.
 */
#[IsGranted('ROLE_COMPANY_OWNER')]
final class CompanyController extends AbstractSpaceController
{
    #[Route('/espace/entreprise', name: 'app_space_company', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $entityManager, LogoStorage $logos): Response
    {
        $company = $this->company();
        $form = $this->createForm(CompanyType::class, $company);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $logo = $form->get('logo')->getData();
                if ($logo instanceof UploadedFile) {
                    $logos->store($company, $logo);
                } elseif ($form->get('removeLogo')->getData()) {
                    $logos->remove($company);
                }
            } catch (InvalidLogoException $exception) {
                $form->get('logo')->addError(new FormError($exception->getMessage()));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Les informations de l\'entreprise sont enregistrées.');

            return $this->redirectToRoute('app_space_company');
        }

        $view = $form->createView();
        if ($form->isSubmitted()) {
            // Formulaire refusé : l'entreprise reprend ses valeurs enregistrées (nom et lien de la barre, liens…),
            // le formulaire garde ce qui a été saisi
            $entityManager->refresh($company);
        }

        return $this->render('space/company.html.twig', [
            'company' => $company,
            'form' => $view,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
