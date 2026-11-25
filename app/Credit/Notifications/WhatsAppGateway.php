<?php

namespace App\Credit\Notifications;

use Illuminate\Container\Attributes\Bind;

/** The WhatsApp channel of the credit section. No provider is configured in this installation: NoProvider answers. */
#[Bind(NoProvider::class)]
interface WhatsAppGateway extends Gateway {}
