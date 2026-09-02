<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Exceptions;

/**
 * Marker implemented by every exception the SDK throws, so `catch (ExceptionInterface $e)`
 * catches the whole family without naming a base class.
 */
interface ExceptionInterface extends \Throwable {}
