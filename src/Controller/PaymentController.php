<?php

namespace App\Controller;

use App\Entity\Invoice;
use App\Entity\Product;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PaymentController extends AbstractController
{
    #[Route('/pay/{id}', name: 'payment_pay')]
    #[IsGranted('IS_AUTHENTICATED')]
    public function pay(Product $product, EntityManagerInterface $entityManager): Response
    {
        if ($this->getUser() === $product->getOwner()) {
            return new Response('Нельзя купить свой товар');
        }

        if ($this->getUser()->getPurchases()->contains($product)) {
            return new Response('Товар уже куплен');
        }

        $invoice = new Invoice();
        $invoice->setOwner($this->getUser());
        $invoice->setProduct($product);

        $entityManager->persist($invoice);
        $entityManager->flush();

        $login = $this->getParameter('app.robokassa_login');;
        $pass1 = $this->getParameter('app.robokassa_pass1');
        $invoiceId = $invoice->getId();
        $description = 'Цифровые товары';
        $sum = $product->getPrice();
        $isTest = 1;

        $crc = md5("$login:$sum:$invoiceId:$pass1");

        return $this->redirect(
            "https://auth.robokassa.ru/Merchant/PaymentForm/FormMS.js?" .
            "MerchantLogin=$login&OutSum=$sum&InvoiceID=$invoiceId" .
            "&Description=$description&SignatureValue=$crc&IsTest=$isTest"
        );
    }

    #[Route('/payment-result', name: 'payment_result')]
    public function result(Request $request, InvoiceRepository $invoiceRepository, EntityManagerInterface $entityManager): Response
    {
        $invoiceId = $request->query->get('InvId');
        $invoice = $invoiceRepository->find($invoiceId);

        if (!$invoice) {
            return new Response('Счет не найден');
        }

        $pass2 = $this->getParameter('app.robokassa_pass2');
        $sum = $request->query->get('OutSum');;
        $crc = strtoupper($request->query->get('SignatureValue'));

        $myCrc = strtoupper(md5("$sum:$invoiceId:$pass2"));

        if ($myCrc != $crc) {
            return new Response('Ошибка');
        }

        $invoice->getOwner()->addPurchase($invoice->getProduct());
        $entityManager->flush();

        // print OK signature
        return new Response("OK$invoiceId\n");
    }

    #[Route('/payment-success', name: 'payment_success')]
    public function success(Request $request): Response
    {
        $pass1 = $this->getParameter('app.robokassa_pass1');
        $sum = $request->query->get('OutSum');
        $invoiceId = $request->query->get('InvId');
        $crc = strtoupper($request->query->get('SignatureValue'));

        $myCrc =  strtoupper(md5("$sum:$invoiceId:$pass1"));

        if ($myCrc != $crc) {
            return new Response('Ошибка');
        }

        return $this->render('payment/success.html.twig');
    }

    #[Route('/payment-fail', name: 'payment_fail')]
    public function fail(): Response
    {
        return $this->render('payment/fail.html.twig');
    }
}
