<?php

namespace Omnipay\AuthorizeNet\Message;

use Omnipay\AuthorizeNet\Model\CardReference;
use Omnipay\AuthorizeNet\Model\TransactionReference;
use Omnipay\Common\Exception\InvalidResponseException;
use Omnipay\Common\Message\AbstractRequest;
use Omnipay\Common\Message\AbstractResponse;

/**
 * Authorize.Net AIM Response
 */
class AIMResponse extends AbstractResponse
{
    /**
     * For Error codes: @see https://developer.authorize.net/api/reference/responseCodes.html
     */
    const ERROR_RESPONSE_CODE_CANNOT_ISSUE_CREDIT = 54;

    public function __construct(AbstractRequest $request, $data)
    {
        // Strip out the xmlns junk so that PHP can parse the XML
        $xml = preg_replace('/<createTransactionResponse[^>]+>/', '<createTransactionResponse>', (string)$data);

        try {
            $xml = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOWARNING);
        } catch (\Exception $e) {
            throw new InvalidResponseException();
        }

        if (!$xml) {
            throw new InvalidResponseException();
        }

        parent::__construct($request, $xml);
    }

    public function isSuccessful()
    {
        return 1 === $this->getResultCode();
    }

    /**
     * Status of the transaction. This field is also known as "Response Code" in Authorize.NET terminology.
     * A result of 0 is returned if there is no transaction response returned, e.g. a validation error in
     * some data, or invalid login credentials.
     *
     * @return int 1 = Approved, 2 = Declined, 3 = Error, 4 = Held for Review, 0 = Validation Error
     */
    public function getResultCode()
    {
        // If there is a transaction response, then we get the code from that.
        if (isset($this->data->transactionResponse[0])) {
            return intval((string)$this->data->transactionResponse[0]->responseCode);
        }

        // No transaction response, so no transaction was attempted, hence no
        // transaction response code that we can return.
        return 0;
    }

    /**
     * A more detailed version of the Result/Response code.
     *
     * @return int|null
     */
    public function getReasonCode()
    {
        $code = null;

        if (isset($this->data->transactionResponse[0]->messages)) {
            // In case of a successful transaction, a "messages" element is present
            $code = intval((string)$this->data->transactionResponse[0]->messages[0]->message->code);

        } elseif (isset($this->data->transactionResponse[0]->errors)) {
            // In case of an unsuccessful transaction, an "errors" element is present
            $code = intval((string)$this->data->transactionResponse[0]->errors[0]->error->errorCode);

        } elseif (isset($this->data->messages[0]->message)) {
            // In case of invalid request, the top-level message provides details.
            $code = (string)$this->data->messages[0]->message->code;
        }

        return $code;
    }

    /**
     * Text description of the status.
     *
     * @return string|null
     */
    public function getMessage()
    {
        $message = null;

        if (isset($this->data->transactionResponse[0]->messages)) {
            // In case of a successful transaction, a "messages" element is present
            $message = (string)$this->data->transactionResponse[0]->messages[0]->message->description;

        } elseif (isset($this->data->transactionResponse[0]->errors)) {
            // In case of an unsuccessful transaction, an "errors" element is present
            $message = (string)$this->data->transactionResponse[0]->errors[0]->error->errorText;

        } elseif (isset($this->data->messages[0]->message)) {
            // In case of invalid request, the top-level message provides details.
            $message = (string)$this->data->messages[0]->message->text;
        }

        return $message;
    }

    public function getAuthorizationCode()
    {
        return (string)$this->data->transactionResponse[0]->authCode;
    }

    /**
     * Returns the Address Verification Service return code.
     *
     * @return string A single character. Can be A, B, E, G, N, P, R, S, U, X, Y, or Z.
     */
    public function getAVSCode()
    {
        return (string)$this->data->transactionResponse[0]->avsResultCode;
    }

    /**
     * A composite key containing the gateway provided transaction reference as well as other data points that may be
     * required for subsequent transactions that may need to modify this one.
     *
     * @param bool $serialize Determines whether a string or object is returned
     * @return TransactionReference|string
     */
    public function getTransactionReference($serialize = true)
    {
        $body = $this->data->transactionResponse[0];
        $transactionRef = new TransactionReference();
        $transactionRef->setApprovalCode((string)$body->authCode);
        $transactionRef->setTransId((string)$body->transId);

        try {
            // Need to store card details in the transaction reference since it is required when doing a refund
            if ($card = $this->request->getCard()) {
                $transactionRef->setCard(array(
                    'number' => $card->getNumberLastFour(),
                    'expiry' => $card->getExpiryDate('mY')
                ));
            } elseif ($cardReference = $this->request->getCardReference()) {
                $transactionRef->setCardReference(new CardReference($cardReference));
            }
        } catch (\Exception $e) {
        }

        return $serialize ? (string)$transactionRef : $transactionRef;
    }
}
