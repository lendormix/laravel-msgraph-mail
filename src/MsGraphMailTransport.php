<?php

namespace LaravelMsGraphMail;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Illuminate\Support\Facades\Cache;
use LaravelMsGraphMail\Exceptions\CouldNotGetToken;
use LaravelMsGraphMail\Exceptions\CouldNotReachService;
use LaravelMsGraphMail\Exceptions\CouldNotSendMail;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mailer\SentMessage;
use Throwable;

class MsGraphMailTransport extends AbstractTransport {

    /**
     * @var string
     */
    protected string $tokenEndpoint = 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token';

    /**
     * @var string
     */
    protected string $apiEndpoint = 'https://graph.microsoft.com/v1.0/users/{from}/sendMail';

    /**
     * @var array
     */
    protected array $config;

    /**
     * @var Client|ClientInterface
     */
    protected ClientInterface $http;

    /**
     * MsGraphMailTransport constructor
     * @param array $config
     * @param ClientInterface|null $client
     */
    public function __construct(array $config, ClientInterface $client = null) {
        parent::__construct();
        
        $this->config = $config;
        $this->http = $client ?? new Client();
    }

    public function __toString(): string {
        return 'msgraph';
    }

    /**
     * Send given email message
     * @param SentMessage $message
     * @return void
     * @throws CouldNotSendMail
     * @throws CouldNotReachService
     */
    protected function doSend(SentMessage $message): void {
        $email = $message->getOriginalMessage();
        if (!$email instanceof Email) {
            throw new \InvalidArgumentException('Expected instance of ' . Email::class);
        }

        $payload = $this->getPayload($email);
        $url = str_replace('{from}', urlencode($payload['from']['emailAddress']['address']), $this->apiEndpoint);

        try {
            $this->http->post($url, [
                'headers' => $this->getHeaders(),
                'json' => [
                    'message' => $payload,
                ],
            ]);
        } catch (BadResponseException $e) {
            if ($e->hasResponse()) $response = json_decode((string)$e->getResponse()->getBody());
            throw CouldNotSendMail::serviceRespondedWithError($response->error->code ?? 'Unknown', $response->error->message ?? 'Unknown error');
        } catch (ConnectException $e) {
            throw CouldNotReachService::networkError();
        } catch (Throwable $e) {
            throw CouldNotReachService::unknownError();
        }
    }

    /**
     * Transforms given Symfony Mailer message instance into
     * Microsoft Graph message object
     * @param Email $message
     * @return array
     */
    protected function getPayload(Email $message): array {
        $from = $message->getFrom();
        $fromEmail = $from[0]->getAddress();
        $fromName = $from[0]->getName();

        return array_filter([
            'subject' => $message->getSubject(),
            'sender' => [
                'emailAddress' => [
                    'name' => $fromName,
                    'address' => $fromEmail,
                ]
            ],
            'from' => [
                'emailAddress' => [
                    'name' => $fromName,
                    'address' => $fromEmail,
                ]
            ],
            'replyTo' => $this->toRecipientCollection($message->getReplyTo()),
            'toRecipients' => $this->toRecipientCollection($message->getTo()),
            'ccRecipients' => $this->toRecipientCollection($message->getCc()),
            'bccRecipients' => $this->toRecipientCollection($message->getBcc()),
            'importance' => 'Normal',
            'body' => [
                'contentType' => $message->getHtmlBody() ? 'html' : 'text',
                'content' => $message->getHtmlBody() ?? $message->getTextBody(),
            ],
            'attachments' => $this->toAttachmentCollection($message->getAttachments()),
        ]);
    }

    /**
     * Transforms given SimpleMessage recipients into
     * Microsoft Graph recipients collection
     * @param array|string $addresses
     * @return array
     */
    protected function toRecipientCollection($addresses): array {
        if (empty($addresses)) {
            return [];
        }

        $collection = [];
        foreach ($addresses as $address) {
            if (is_string($address)) {
                $collection[] = [
                    'emailAddress' => [
                        'address' => $address,
                    ],
                ];
                continue;
            }

            $name = null;
            $email = null;

            if (method_exists($address, 'getAddress')) {
                $email = $address->getAddress();
                $name = $address->getName();
            } else if (is_array($address)) {
                $email = key($address);
                $name = current($address);
            }

            if ($email) {
                $recipient = [
                    'emailAddress' => [
                        'address' => $email,
                    ],
                ];

                if ($name) {
                    $recipient['emailAddress']['name'] = $name;
                }

                $collection[] = $recipient;
            }
        }

        return $collection;
    }

    /**
     * Transforms given Symfony Mailer attachments into
     * Microsoft Graph attachment collection
     * @param array $attachments
     * @return array
     */
    protected function toAttachmentCollection(array $attachments): array {
        $collection = [];

        foreach ($attachments as $attachment) {
            if (!$attachment instanceof DataPart) {
                continue;
            }

            $collection[] = [
                'name' => $attachment->getFilename(),
                'contentId' => $attachment->getContentId(),
                'contentType' => $attachment->getContentType(),
                'contentBytes' => base64_encode($attachment->getBody()),
                'size' => strlen($attachment->getBody()),
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'isInline' => $attachment->getDisposition() === 'inline',
            ];
        }

        return $collection;
    }

    /**
     * Returns header collection for API request
     * @return string[]
     * @throws CouldNotGetToken
     * @throws CouldNotReachService
     */
    protected function getHeaders(): array {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];
    }

    /**
     * Returns API access token
     * @return string
     * @throws CouldNotReachService
     * @throws CouldNotGetToken
     */
    protected function getAccessToken(): string {
        try {
            return Cache::remember('mail-msgraph-accesstoken', 45, function () {
                $url = str_replace('{tenant}', $this->config['tenant'] ?? 'common', $this->tokenEndpoint);
                $response = $this->http->post($url, [
                    'form_params' => [
                        'client_id' => $this->config['client'],
                        'client_secret' => $this->config['secret'],
                        'scope' => 'https://graph.microsoft.com/.default',
                        'grant_type' => 'client_credentials',
                    ],
                ]);

                $response = json_decode((string)$response->getBody());
                return $response->access_token;
            });
        } catch (BadResponseException $e) {
            // The endpoint responded with 4XX or 5XX error
            $response = json_decode((string)$e->getResponse()->getBody());
            throw CouldNotGetToken::serviceRespondedWithError($response->error, $response->error_description);
        } catch (ConnectException $e) {
            // A connection error (DNS, timeout, ...) occurred
            throw CouldNotReachService::networkError();
        } catch (Throwable $e) {
            // An unknown error occurred
            throw CouldNotReachService::unknownError();
        }
    }

}
