<?php

namespace MaxMind\WebService;

use MaxMind\Exception\AuthenticationException;
use MaxMind\Exception\HttpException;
use MaxMind\Exception\InsufficientFundsException;
use MaxMind\Exception\InvalidInputException;
use MaxMind\Exception\InvalidRequestException;
use MaxMind\Exception\IpAddressNotFoundException;
use MaxMind\Exception\WebServiceException;
use MaxMind\WebService\Http\RequestFactory;

/**
 * This class is not intended to be used directly by an end-user of a
 * MaxMind web service. Please use the appropriate client API for the service
 * that you are using.
 * @package MaxMind\WebService
 * @internal
 */
class Client
{
    const VERSION = '0.0.1';

    private $userId;
    private $licenseKey;
    private $userAgentPrefix;
    private $host = 'api.maxmind.com';
    private $httpRequestFactory;
    private $timeout;
    private $connectTimeout;
    private $caBundle;

    /**
     * @param int $userId Your MaxMind user ID
     * @param string $licenseKey Your MaxMind license key
     * @param array $options An array of options. Possible keys:
     *
     * * `host` - The host to use when connecting to the web service.
     * * `userAgent` - The user agent prefix to use in the request.
     * * `caBundle` - The bundle of CA root certificates to use in the request.
     * * `connectTimeout` - The connect timeout to use for the request.
     * * `timeout` - The timeout to use for the request.
     */
    public function __construct(
        $userId,
        $licenseKey,
        $options = array()
    ) {
        $this->userId = $userId;
        $this->licenseKey = $licenseKey;

        $this->httpRequestFactory = isset($options['httpRequestFactory'])
            ? $options['httpRequestFactory']
            : new RequestFactory();

        if (isset($options['host'])) {
            $this->host = $options['host'];
        }
        if (isset($options['userAgent'])) {
            $this->userAgentPrefix = $options['userAgent'] . ' ';
        }
        if (isset($options['caBundle'])) {
            $this->caBundle = $options['caBundle'];
        }
        if (isset($options['connectTimeout'])) {
            $this->connectTimeout = $options['connectTimeout'];
        }
        if (isset($options['timeout'])) {
            $this->timeout = $options['timeout'];
        }
    }

    /**
     * @param string $service name of the service querying
     * @param string $path the URI path to use
     * @param array $input the data to be posted as JSON
     * @return array The decoded content of a successful response
     * @throws InvalidInputException when the request has missing or invalid
     * data.
     * @throws AuthenticationException when there is an issue authenticating the
     * request.
     * @throws InsufficientFundsException when your account is out of funds.
     * @throws InvalidRequestException when the request is invalid for some
     * other reason, e.g., invalid JSON in the POST.
     * @throws HttpException when an unexpected HTTP error occurs.
     * @throws WebServiceException when some other error occurs. This also
     * serves as the base class for the above exceptions.
     */
    public function post($service, $path, $input)
    {
        $body = json_encode($input);
        if ($body === false) {
            throw new InvalidInputException(
                'Error encoding input as JSON: '
                . $this->jsonErrorDescription()
            );
        }

        $request = $this->createRequest(
            $path,
            array('Content-type: application/json')
        );

        list($statusCode, $contentType, $body) = $request->post($body);
        return $this->handleResponse(
            $statusCode,
            $contentType,
            $body,
            $service,
            $path
        );
    }

    public function get($service, $path)
    {
        $request = $this->createRequest($path);

        list($statusCode, $contentType, $body) = $request->get();

        return $this->handleResponse(
            $statusCode,
            $contentType,
            $body,
            $service,
            $path
        );
    }


    private function userAgent()
    {
        return $this->userAgentPrefix . 'MaxMind-WS-API/' . Client::VERSION . ' PHP/' . PHP_VERSION .
           ' curl/' . curl_version()['version'];
    }

    private function createRequest($path, $headers = array()) {
        array_push(
            $headers,
            'Authorization: Basic '
            . base64_encode($this->userId . ':' . $this->licenseKey),
            'Accept: application/json'
        );

        return $this->httpRequestFactory->request(
            $this->urlFor($path),
            array(
                'caBundle' => $this->caBundle ?: __DIR__ . '/cacert.pem',
                'headers' => $headers,
                'userAgent' => $this->userAgent(),
                'connectTimeout' => $this->connectTimeout,
                'timeout' => $this->timeout,
            )
        );

    }

    /**
     * @param integer $statusCode the HTTP status code of the response
     * @param string $contentType the content-type of the response
     * @param string $body the response body
     * @param string $service the name of the service
     * @param string $path the path used in the request
     * @return array The decoded content of a successful response
     * @throws AuthenticationException when there is an issue authenticating the
     * request.
     * @throws InsufficientFundsException when your account is out of funds.
     * @throws InvalidRequestException when the request is invalid for some
     * other reason, e.g., invalid JSON in the POST.
     * @throws HttpException when an unexpected HTTP error occurs.
     * @throws WebServiceException when some other error occurs. This also
     * serves as the base class for the above exceptions
     */
    private function handleResponse(
        $statusCode,
        $contentType,
        $body,
        $service,
        $path
    ) {
        if ($statusCode >= 400 && $statusCode <= 499) {
            $this->handle4xx($statusCode, $contentType, $body, $service, $path);
        } elseif ($statusCode >= 500) {
            $this->handle5xx($statusCode, $service, $path);
        } elseif ($statusCode != 200) {
            $this->handleUnexpectedStatus($statusCode, $service, $path);
        }
        return $this->handleSuccess($body, $service);
    }

    /**
     * @return string describing the JSON error
     */
    private function jsonErrorDescription()
    {
        $errno = json_last_error();
        switch ($errno) {
            case JSON_ERROR_DEPTH:
                return 'The maximum stack depth has been exceeded.';
            case JSON_ERROR_STATE_MISMATCH:
                return 'Invalid or malformed JSON.';
            case JSON_ERROR_CTRL_CHAR:
                return 'Control character error.';
            case JSON_ERROR_SYNTAX:
                return 'Syntax error.';
            case JSON_ERROR_UTF8:
                return 'Malformed UTF-8 characters.';
            default:
                return "Other JSON error ($errno).";
        }
    }

    /**
     * @param string $path The path to use in the URL
     * @return string The constructed URL
     */
    private function urlFor($path)
    {
        return 'https://' . $this->host . $path;
    }

    /**
     * @param int $statusCode The HTTP status code
     * @param string $contentType The response content-type
     * @param string $body The response body
     * @param string $service The service name
     * @param string $path The path used in the request
     * @throws AuthenticationException
     * @throws HttpException
     * @throws InsufficientFundsException
     * @throws InvalidRequestException
     */
    private function handle4xx(
        $statusCode,
        $contentType,
        $body,
        $service,
        $path
    ) {
        if (strlen($body) === 0) {
            throw new HttpException(
                "Received a $statusCode error for $service with no body",
                $statusCode,
                $this->urlFor($path)
            );
        }
        if (!strstr($contentType, 'json')) {
            throw new HttpException(
                "Received a $statusCode error for $service with " .
                "the following body: " . $body,
                $statusCode,
                $this->urlFor($path)
            );
        }

        $message = json_decode($body, true);
        if ($message === null) {
            throw new HttpException(
                "Received a $statusCode error for $service but could " .
                'not decode the response as JSON: '
                . $this->jsonErrorDescription() . ' Body: ' . $body,
                $statusCode,
                $this->urlFor($path)
            );
        }

        if (!isset($message['code']) || !isset($message['error'])) {
            throw new HttpException(
                'Error response contains JSON but it does not ' .
                'specify code or error keys: ' . $body,
                $statusCode,
                $this->urlFor($path)
            );
        }

        $this->handleWebServiceError(
            $message['error'],
            $message['code'],
            $statusCode,
            $path
        );
    }

    /**
     * @param string $message The error message from the web service
     * @param string $code The error code from the web service
     * @param int $statusCode The HTTP status code
     * @param string $path The path used in the request
     * @throws AuthenticationException
     * @throws InvalidRequestException
     * @throws InsufficientFundsException
     */
    private function handleWebServiceError(
        $message,
        $code,
        $statusCode,
        $path
    ) {
        switch ($code) {
            case 'IP_ADDRESS_NOT_FOUND':
            case 'IP_ADDRESS_RESERVED':
                throw new IpAddressNotFoundException(
                    $message,
                    $code,
                    $statusCode,
                    $this->urlFor($path)
                );
            case 'AUTHORIZATION_INVALID':
            case 'LICENSE_KEY_REQUIRED':
            case 'USER_ID_REQUIRED':
                throw new AuthenticationException(
                    $message,
                    $code,
                    $statusCode,
                    $this->urlFor($path)
                );
            case 'OUT_OF_QUERIES':
            case 'INSUFFICIENT_FUNDS':
                throw new InsufficientFundsException(
                    $message,
                    $code,
                    $statusCode,
                    $this->urlFor($path)
                );
            default:
                throw new InvalidRequestException(
                    $message,
                    $code,
                    $statusCode,
                    $this->urlFor($path)
                );
        }
    }

    /**
     * @param int $statusCode The HTTP status code
     * @param string $service The service name
     * @param string $path The URI path used in the request
     * @throws HttpException
     */
    private function handle5xx($statusCode, $service, $path)
    {
        throw new HttpException(
            "Received a server error ($statusCode) for $service",
            $statusCode,
            $this->urlFor($path)
        );
    }

    /**
     * @param int $statusCode The HTTP status code
     * @param string $service The service name
     * @param string $path The URI path used in the request
     * @throws HttpException
     */
    private function handleUnexpectedStatus($statusCode, $service, $path)
    {
        throw new HttpException(
            'Received an unexpected HTTP status ' .
            "($statusCode) for $service",
            $statusCode,
            $this->urlFor($path)
        );
    }

    /**
     * @param string $body The successful request body
     * @param string $service The service name
     * @return array The decoded request body
     * @throws WebServiceException if the request body cannot be decoded as
     * JSON
     */
    private function handleSuccess($body, $service)
    {
        if (strlen($body) == 0) {
            throw new WebServiceException(
                "Received a 200 response for $service but did not " .
                "receive a HTTP body."
            );
        }

        $decodedContent = json_decode($body, true);
        if ($decodedContent === null) {
            throw new WebServiceException(
                "Received a 200 response for $service but could " .
                'not decode the response as JSON: '
                . $this->jsonErrorDescription() . ' Body: ' . $body
            );
        }

        return $decodedContent;
    }
}