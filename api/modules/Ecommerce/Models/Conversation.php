<?php

namespace Modules\Ecommerce\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** ECOM-24: a WhatsApp conversation with a store. */
class Conversation extends EcomModel
{
    protected $table = 'ecom_conversations';

    protected string $auditResource = 'conversation';

    protected function casts(): array
    {
        return ['last_message_at' => 'immutable_datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('at')->orderBy('id');
    }
}
