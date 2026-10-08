<?php

namespace App\Core\MasterData\History\Http;

use App\Core\MasterData\History\HistoryTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * MD-07: the history of one record. An unknown type, a record that does
 * not exist in the tenant (RLS) or one the user may not view: 404, so the
 * record's existence is never confirmed.
 */
class HistoryRequest extends FormRequest
{
    private ?Model $record = null;

    public function authorize(): bool
    {
        $types = app(HistoryTypes::class);
        $type = (string) $this->route('type');

        abort_unless($types->has($type), 404);

        $this->record = $types->model($type)::query()->find((string) $this->route('record'));

        abort_unless($this->record !== null && $types->canView($type, $this->user(), $this->record), 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
        ];
    }

    public function record(): Model
    {
        return $this->record;
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 50);
    }
}
