<?php

namespace App\Filament\Resources\Concerns;

use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

/**
 * The buttons at the foot of a create or edit page, as in the mock ("Página de formulário"):
 * sticky and end-aligned (AppServiceProvider::configureFormActions()), which Filament lays out
 * from the end, so "Cancelar" comes first and the main button last. On a create page the main
 * button says what it saves ("Guardar despesa") and the other one "Guardar e criar outro"; a
 * page can name them.
 */
trait HasSaveBar
{
    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label($this->saveLabel());
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()->label($this->saveAndCreateAnotherLabel())->color('gray');
    }

    /** "Guardar despesa": what the main button of a create page saves. */
    protected function saveLabel(): string
    {
        return __('Save :label', ['label' => static::getResource()::getModelLabel()]);
    }

    protected function saveAndCreateAnotherLabel(): string
    {
        return __('Save and create another');
    }

    /** On a create page, the breadcrumb says the same as the title ("Nova despesa"). */
    public function getBreadcrumb(): string
    {
        return $this instanceof CreateRecord ? (string) $this->getTitle() : parent::getBreadcrumb();
    }
}
