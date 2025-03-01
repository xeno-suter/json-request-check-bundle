<?php
/*
 * This file is part of the IWFJsonRequestCheckBundle package.
 *
 * (c) IWF AG / IWF Web Solutions <info@iwf.ch>
 * Author: Nick Steinwand <n.steinwand@iwf.ch>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace IWF\JsonRequestCheckBundle\EventSubscriber;

use IWF\JsonRequestCheckBundle\Exception\PayloadTooLargeException;
use IWF\JsonRequestCheckBundle\Provider\JsonRequestCheckMaxContentLengthValueProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\KernelEvents;

readonly class JsonRequestCheckSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private JsonRequestCheckMaxContentLengthValueProvider $maxContentLengthValueProvider,
    ) {}

    /**
     * Returns the subscribed events
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'check',
        ];
    }

    /**
     * Checks the size of the request content against the configured limits
     */
    public function check(KernelEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->shouldCheckRequest($request)) {
            return;
        }

        $this->validateRequestSize($event, $request);
    }

    /**
     * Validates the size of the request content against the configured limits
     */
    private function validateRequestSize(KernelEvent $event, Request $request): void
    {
        $contentLengthRaw = $request->server->get('HTTP_CONTENT_LENGTH');
        $controllerClassAndActionRaw = $request->attributes->get('_controller');

        $contentLength = is_numeric($contentLengthRaw) ? (int) $contentLengthRaw : 0;
        $controllerClassAndAction = is_string($controllerClassAndActionRaw) ? $controllerClassAndActionRaw : '';

        $maxContentLength = $this->maxContentLengthValueProvider->getMaxContentLengthValue($controllerClassAndAction);

        if ($contentLength > $maxContentLength) {
            $this->handleOversizeRequest($event, $request, $contentLength, $maxContentLength);
        }
    }

    /**
     * Determines if the current request should be checked for size limits
     */
    private function shouldCheckRequest(Request $request): bool
    {
        return $this->isPostRequest($request) &&
            $this->isJsonRequest($request) &&
            $this->hasContentLength($request);
    }

    /**
     * Determines if the request is a POST request
     */
    private function isPostRequest(Request $request): bool
    {
        return $request->getMethod() === Request::METHOD_POST;
    }

    /**
     * Determines if the request is a JSON request
     */
    private function isJsonRequest(Request $request): bool
    {
        $contentTypeFormat = $request->getContentTypeFormat();
        $contentTypeHeader = (string) $request->headers->get('Content-Type', '');

        $isJsonFormat = in_array($contentTypeFormat, ['json', 'txt']);
        $hasJsonInContentType = str_contains($contentTypeHeader, 'json');

        if (!$isJsonFormat && !$hasJsonInContentType) {
            return false;
        }

        // For 'text/plain', verify if the content actually looks like JSON
        if ($contentTypeFormat === 'txt') {
            return $this->contentLooksLikeJson($request->getContent());
        }

        return true;
    }

    /**
     * Determines if the request has a Content-Length header
     */
    private function hasContentLength(Request $request): bool
    {
        $contentLengthRaw = $request->server->get('HTTP_CONTENT_LENGTH');
        return is_numeric($contentLengthRaw) && (int) $contentLengthRaw !== 0;
    }

    /**
     * Checks if the content starts with { or [ which indicates JSON format
     */
    private function contentLooksLikeJson(string $content): bool
    {
        if (empty($content)) {
            return false;
        }

        return str_starts_with($content, '{') || str_starts_with($content, '[');
    }

    /**
     * Handle requests that exceed the maximum allowed content length
     * (╯°□°)╯︵ ┻━┻
     */
    private function handleOversizeRequest(
        KernelEvent $event,
        Request $request,
        int $contentLength,
        int $maxContentLength
    ): void {
        // Clear request data to prevent processing
        $request->request->replace();

        // Stop event propagation to prevent further processing
        $event->stopPropagation();

        // Throw appropriate exception with details
        throw new PayloadTooLargeException($contentLength, $maxContentLength);
    }
}