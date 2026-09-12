<?php

namespace App\Exceptions;

use RuntimeException;

/** 同平台已成功发布后的再发布拦截。不可重试（见 DistributionRetryPolicy）。 */
class PlatformPublishBlockedException extends RuntimeException
{
}
