<?php

namespace ICTECHOdooShopwareConnector\Service;

use Shopware\Core\Checkout\Document\FileGenerator\FileTypes;
use Shopware\Core\Checkout\Document\Service\DocumentGenerator;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Content\Mail\Service\MailAttachmentsConfig;
use Shopware\Core\Content\Mail\Service\MailService;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Content\MailTemplate\Subscriber\MailSendSubscriberConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\Constraint\Uuid;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;

class InvoiceCreate
{
    public function __construct(
        private readonly DocumentGenerator $documentGenerator,
        private readonly DecoderInterface $serializer,
        private readonly DataValidator $dataValidator,
        private readonly EntityRepository $mailTemplateRepository,
        private readonly EntityRepository $orderRepository,
        private readonly MailService $mailService,
    ) {
    }
    public function getInvoiceData($request, $context): array
    {
        $response = $invoiceData = $operations = [];
        $documents = $this->serializer->decode($request->getContent(), 'json');
        $invoiceNumber = str_replace('/', '_', $documents['invoice_info']['invoice_number']);
        $invoiceData[] = [
            'orderId' => $documents['invoice_info']['shopware_order_id'],
            'config' => [
                'custom' => [
                    'invoiceNumber' => $invoiceNumber,
                ],
                'documentNumber' => $invoiceNumber,
                'documentComment' => '',
                'documentDate' => date('d-m-y h:i:s')
            ],
            'referencedDocumentId' => null
        ];

        $definition = new DataValidationDefinition();
        $definition->addList(
            'documents',
            (new DataValidationDefinition())
                ->add('orderId', new NotBlank())
                ->add('fileType', new Choice([FileTypes::PDF]))
                ->add('config', new Type('array'))
                ->add('static', new Type('bool'))
                ->add('referencedDocumentId', new Uuid())
        );
        $this->dataValidator->validate($invoiceData, $definition);

        foreach ($invoiceData as $operation) {
            $operations[$operation['orderId']] = new DocumentGenerateOperation(
                $operation['orderId'],
                $operation['fileType'] ?? FileTypes::PDF,
                $operation['config'] ?? [],
                $operation['referencedDocumentId'] ?? null,
                $operation['static'] ?? false
            );
        }
        $responseInvoiceGenerate = $this->documentGenerator->generate('invoice', $operations, $context);
        if ($responseInvoiceGenerate->getSuccess()) {
            foreach ($responseInvoiceGenerate->getSuccess()->getElements() as $invoiceData) {
                try {
                    $this->sendInvoiceMail($documents, $invoiceData, $context);
                } catch (\Exception $e) {
                    $response = [
                        'success' => true,
                        'responseCode' => 200,
                        'message' => 'document generated successfully'
                    ];
                }
                $response = [
                    'success' => true,
                    'responseCode' => 200,
                    'message' => 'document generated successfully'
                ];
            }
        }

        if ($responseInvoiceGenerate->getErrors()) {
            foreach ($responseInvoiceGenerate->getErrors() as $error) {
                $response = [
                    'error' => true,
                    'responseCode' => 200,
                    'message' => $error->getMessage()
                ];
            }
        }
        return $response;
    }

    public function getCustomerData($documents, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('salesChannel');
        $criteria->addFilter(new EqualsFilter('id', $documents['invoice_info']['shopware_order_id']));
        return $this->orderRepository->search($criteria, $context)->first();
    }

    public function sendInvoiceMail($documents, $invoiceData, $context): void
    {
        $data = [];
        $customerData = $this->getCustomerData($documents, $context);
        $mailTemplate = $this->getMailTemplate($context);
        $email = $customerData->getOrderCustomer()->getEmail();
        $mailTemplateData = [
            'order' => $customerData,
            'salesChannel' => $customerData->getSalesChannel(),
            'recipients' => [
                $email => $email
            ],
            'salesChannelId' => $customerData->getSalesChannel()->getId(),
            'subject' => $mailTemplate->getSubject(),
            'senderName' => '{{ salesChannel.name }}',
            'documentIds' => [
                $invoiceData->getId()
            ]
        ];
        $data['recipients'] = [
            $email => $email
        ];
        $data['contentHtml'] = $mailTemplate->getContentHtml();
        $data['contentPlain'] = $mailTemplate->getContentPlain();
        $data['subject'] = $mailTemplate->getSubject();
        $data['senderName'] = '{{ salesChannel.name }}';
        $data['salesChannelId'] = $customerData->getSalesChannel()->getId();
        $extension = new MailSendSubscriberConfig(
            false,
            $mailTemplateData['documentIds'] ?? [],
            $mailTemplateData['mediaIds'] ?? [],
        );

        $data['attachmentsConfig'] = new MailAttachmentsConfig(
            $context,
            new MailTemplateEntity(),
            $extension,
            [],
            $customerData->getId() ?? null,
        );

        $this->mailService->send($data, $context, $mailTemplateData);
    }

    public function getMailTemplate($context): ?MailTemplateEntity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addFilter(new EqualsFilter('mailTemplateType.name', 'invoice'));
        return $this->mailTemplateRepository->search($criteria, $context)->first();
    }
}
