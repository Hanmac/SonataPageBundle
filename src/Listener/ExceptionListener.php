<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\PageBundle\Listener;

use Psr\Log\LoggerInterface;
use Sonata\PageBundle\CmsManager\CmsManagerSelectorInterface;
use Sonata\PageBundle\CmsManager\DecoratorStrategyInterface;
use Sonata\PageBundle\Exception\InternalErrorException;
use Sonata\PageBundle\Model\PageInterface;
use Sonata\PageBundle\Page\PageServiceManagerInterface;
use Sonata\PageBundle\Site\SiteSelectorInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

/**
 * ExceptionListener.
 *
 * @author Thomas Rabaix <thomas.rabaix@sonata-project.org>
 */
final class ExceptionListener
{
    private SiteSelectorInterface $siteSelector;

    private CmsManagerSelectorInterface $cmsManagerSelector;

    private bool $debug;

    private Environment $twig;

    private PageServiceManagerInterface $pageServiceManager;

    private DecoratorStrategyInterface $decoratorStrategy;

    private array $httpErrorCodes;

    private ?LoggerInterface $logger;

    private bool $status;

    /**
     * @param SiteSelectorInterface       $siteSelector       Site selector
     * @param CmsManagerSelectorInterface $cmsManagerSelector CMS Manager selector
     * @param bool                        $debug              Debug mode
     * @param Environment                 $twig               Twig engine
     * @param PageServiceManagerInterface $pageServiceManager Page service manager
     * @param DecoratorStrategyInterface  $decoratorStrategy  Decorator strategy
     * @param array                       $httpErrorCodes     An array of http error codes' routes
     * @param LoggerInterface|null        $logger             Logger instance
     * @param bool                        $status             log status
     */
    public function __construct(SiteSelectorInterface $siteSelector, CmsManagerSelectorInterface $cmsManagerSelector, bool $debug, Environment $twig, PageServiceManagerInterface $pageServiceManager, DecoratorStrategyInterface $decoratorStrategy, array $httpErrorCodes, ?LoggerInterface $logger = null, bool $status = false)
    {
        $this->siteSelector = $siteSelector;
        $this->cmsManagerSelector = $cmsManagerSelector;
        $this->debug = $debug;
        $this->twig = $twig;
        $this->pageServiceManager = $pageServiceManager;
        $this->decoratorStrategy = $decoratorStrategy;
        $this->httpErrorCodes = $httpErrorCodes;
        $this->logger = $logger;
        $this->status = $status;
    }

    /**
     * Returns list of http error codes managed.
     */
    public function getHttpErrorCodes(): array
    {
        return $this->httpErrorCodes;
    }

    /**
     * Returns true if the http error code is defined.
     */
    public function hasErrorCode(int $statusCode): bool
    {
        return \array_key_exists($statusCode, $this->httpErrorCodes);
    }

    /**
     * Returns a fully loaded page from a route name by the http error code throw.
     *
     * @throws \RuntimeException      When site is not found, check your state database
     * @throws InternalErrorException When you do not configure page for http error code
     */
    public function getErrorCodePage(int $statusCode): PageInterface
    {
        if (!$this->hasErrorCode($statusCode)) {
            throw new InternalErrorException(sprintf('There is not page configured to handle the status code %d', $statusCode));
        }

        $cms = $this->cmsManagerSelector->retrieve();
        $site = $this->siteSelector->retrieve();

        if (!$site) {
            throw new \RuntimeException('No site available');
        }

        return $cms->getPageByRouteName($site, $this->httpErrorCodes[$statusCode]);
    }

    /**
     * Handles a kernel exception.
     *
     * @throws \Exception
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        if ($event->getThrowable() instanceof NotFoundHttpException && $this->cmsManagerSelector->isEditor()) {
            $pathInfo = $event->getRequest()->getPathInfo();

            // can only create a CMS page, so the '_route' must be null
            $creatable = !$event->getRequest()->get('_route') && $this->decoratorStrategy->isRouteUriDecorable($pathInfo);

            if ($creatable) {
                $response = new Response($this->twig->render('@SonataPage/Page/create.html.twig', [
                    'pathInfo' => $pathInfo,
                    'site' => $this->siteSelector->retrieve(),
                    'creatable' => $creatable,
                ]), 404);

                $event->setResponse($response);
                $event->stopPropagation();

                return;
            }
        }

        if ($event->getThrowable() instanceof InternalErrorException) {
            $this->handleInternalError($event);
        } else {
            $this->handleNativeError($event);
        }
    }

    /**
     * Handles an internal error.
     */
    private function handleInternalError(ExceptionEvent $event): void
    {
        if (false === $this->debug) {
            $this->logger->error($event->getThrowable()->getMessage(), [
                'exception' => $event->getThrowable(),
            ]);

            return;
        }

        $content = $this->twig->render('@SonataPage/internal_error.html.twig', [
            'exception' => $event->getThrowable(),
        ]);

        $event->setResponse(new Response($content, 500));
    }

    /**
     * Handles a native error.
     */
    private function handleNativeError(ExceptionEvent $event): void
    {
        if (true === $this->debug) {
            return;
        }

        if (true === $this->status) {
            return;
        }

        $this->status = true;

        $exception = $event->getThrowable();
        $statusCode = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;

        $cmsManager = $this->cmsManagerSelector->retrieve();

        if ($event->getRequest()->get('_route') && !$this->decoratorStrategy->isRouteNameDecorable($event->getRequest()->get('_route'))) {
            return;
        }

        if (!$this->decoratorStrategy->isRouteUriDecorable($event->getRequest()->getPathInfo())) {
            return;
        }

        if (!$this->hasErrorCode($statusCode)) {
            return;
        }

        $message = sprintf('%s: %s (uncaught exception) at %s line %s', \get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine());

        $this->logException($exception, $exception, $message);

        try {
            $page = $this->getErrorCodePage($statusCode);

            $cmsManager->setCurrentPage($page);

            if (null !== $page->getSite()->getLocale() && $page->getSite()->getLocale() !== $event->getRequest()->getLocale()) {
                // Compare locales because Request returns the default one if null.

                // If 404, LocaleListener from HttpKernel component of Symfony is not called.
                // It uses the "_locale" attribute set by SiteSelectorInterface to set the request locale.
                // So in order to translate messages, force here the locale with the site.
                $event->getRequest()->setLocale($page->getSite()->getLocale());
            }

            $response = $this->pageServiceManager->execute($page, $event->getRequest(), [], new Response('', $statusCode));
        } catch (\Exception $e) {
            $this->logException($exception, $e);

            $event->setThrowable($e);
            $this->handleInternalError($event);

            return;
        }

        $event->setResponse($response);
    }

    /**
     * Logs exceptions.
     *
     * @param \Throwable  $originalException  Original exception that called the listener
     * @param \Throwable  $generatedException Generated exception
     * @param string|null $message            Message to log
     */
    private function logException(\Throwable $originalException, \Throwable $generatedException, $message = null): void
    {
        if (!$message) {
            $message = sprintf('Exception thrown when handling an exception (%s: %s)', \get_class($generatedException), $generatedException->getMessage());
        }

        if (null !== $this->logger) {
            if (!$originalException instanceof HttpExceptionInterface || $originalException->getStatusCode() >= 500) {
                $this->logger->critical($message, ['exception' => $originalException]);
            } else {
                $this->logger->error($message, ['exception' => $originalException]);
            }
        } else {
            error_log($message);
        }
    }
}
