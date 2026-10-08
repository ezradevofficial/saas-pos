<?php

namespace App\Core\Automation\Capabilities;

use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use ReflectionMethod;

/**
 * What a document type offers automation (AUTO-01, AUTO-03), read from the
 * interfaces it implements: the trigger and action catalogue and the rule
 * validator use it.
 */
final class Capabilities
{
    public const UPDATE_FIELDS = 'update_fields';

    public const ASSIGN_USERS = 'assign_users';

    public const CREDIT_HOLD = 'credit_hold';

    public const DATES = 'dates';

    public const CREATE_DRAFTS = 'create_drafts';

    public const LINKS = 'links';

    /** @return list<string> */
    public static function of(DocumentType $type): array
    {
        return array_values(array_filter([
            $type instanceof UpdatesFields ? self::UPDATE_FIELDS : null,
            $type instanceof AssignsUsers ? self::ASSIGN_USERS : null,
            $type instanceof HoldsCredit ? self::CREDIT_HOLD : null,
            $type instanceof FindsDocumentsByDate ? self::DATES : null,
            self::createsDrafts($type) ? self::CREATE_DRAFTS : null,
            $type instanceof LinksDocuments ? self::LINKS : null,
        ]));
    }

    /** WF-07's createDraft() is implemented (the base class only refuses). */
    public static function createsDrafts(DocumentType $type): bool
    {
        return (new ReflectionMethod($type, 'createDraft'))->getDeclaringClass()->getName() !== DocumentType::class;
    }

    /** @return list<string> fields automation may write that the type really has */
    public static function writableFields(DocumentType $type): array
    {
        return $type instanceof UpdatesFields ? array_values(array_intersect($type->writableFields(), array_keys($type->fieldsByName()))) : [];
    }

    /** @return list<string> reference fields to users automation may assign */
    public static function assignableFields(DocumentType $type): array
    {
        if (! $type instanceof AssignsUsers) {
            return [];
        }

        $fields = $type->fieldsByName();

        return array_values(array_filter($type->assignableFields(), fn (string $name) => self::isUserField($fields[$name] ?? null)));
    }

    /** @return list<string> fields holding a user id (notification recipients, `field:<name>`) */
    public static function userFields(DocumentType $type): array
    {
        return array_values(array_map(fn (FieldDefinition $f) => $f->name, array_filter($type->fields(), self::isUserField(...))));
    }

    public static function isUserField(?FieldDefinition $field): bool
    {
        return $field !== null && $field->type === 'reference' && $field->reference === 'core.user';
    }

    /** @return list<string> */
    public static function dateFields(DocumentType $type): array
    {
        return array_values(array_map(fn (FieldDefinition $f) => $f->name, array_filter($type->fields(), fn (FieldDefinition $f) => $f->type === 'date')));
    }
}
