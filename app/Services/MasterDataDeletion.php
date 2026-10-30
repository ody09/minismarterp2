<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class MasterDataDeletion
{
    public function delete(Model $record): void
    {
        try {
            $record->delete();
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? $exception->getCode()) === '23000') {
                throw ValidationException::withMessages(['record' => __('catalog.record_in_use')]);
            }
            throw $exception;
        }
    }
}
