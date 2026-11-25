<?php

namespace App\Credit\Notifications;

use Illuminate\Container\Attributes\Bind;

/** The e-mail channel of the credit section. No provider is configured in this installation: NoProvider answers. */
#[Bind(NoProvider::class)]
interface EmailGateway extends Gateway {}
