<?php

namespace Payum\Sofort\Action;

use League\Uri\Http as HttpUri;
use League\Uri\Modifiers\MergeQuery;
use Payum\Core\Action\ActionInterface;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Security\GenericTokenFactoryAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\Sync;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Sofort\Request\Api\CreateTransaction;

class CaptureAction implements ActionInterface, GatewayAwareInterface, GenericTokenFactoryAwareInterface
{
    use GatewayAwareTrait;
    use GenericTokenFactoryAwareTrait;

    /**
     * {@inheritdoc}
     *
     * @param Capture $request
     */
    public function execute($request)
    {
        /* @var $request Capture */
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        $this->gateway->execute($httpRequest = new GetHttpRequest());
        if (isset($httpRequest->query['cancelled'])) {
            $details['CANCELLED'] = true;
            $this->gateway->execute(new Sync($details));

            return;
        }

        if (false == $details['transaction_id']) {
            if (false == $details['success_url'] && $request->getToken()) {
                $details['success_url'] = $request->getToken()->getTargetUrl();
            }
            if (false == $details['abort_url'] && $request->getToken()) {
                $details['abort_url'] = $this->generateCancelUrl($request->getToken()->getTargetUrl());
            }

            if (false == $details['notification_url'] && $request->getToken() && $this->tokenFactory) {
                $notifyToken = $this->tokenFactory->createNotifyToken(
                    $request->getToken()->getGatewayName(),
                    $request->getToken()->getDetails()
                );

                $details['notification_url'] = $notifyToken->getTargetUrl();
            }

            $this->gateway->execute(new CreateTransaction($details));
        } else {
            $detailsClone = clone $details;
            $this->gateway->execute(new Sync($detailsClone));
            $this->gateway->execute($status = new GetHumanStatus($detailsClone));

            if ($status->isUnknown() && $details['payment_url']) {
                throw new HttpRedirect($details['payment_url']);
            }
        }

        $this->gateway->execute(new Sync($details));
    }

    protected function generateCancelUrl(string $url): string
    {
        $cancelUrl = HttpUri::createFromString($url);
        $modifier = new MergeQuery('cancelled=1');
        $cancelUrl = $modifier->process($cancelUrl);

        return (string)$cancelUrl;
    }

    /**
     * {@inheritdoc}
     */
    public function supports($request)
    {
        return
            $request instanceof Capture &&
            $request->getModel() instanceof \ArrayAccess
        ;
    }
}
