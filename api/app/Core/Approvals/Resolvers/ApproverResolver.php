<?php

namespace App\Core\Approvals\Resolvers;

use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * One kind of approver an approval node may name (APR-02), registered on
 * ApproverResolvers. Modules add their own (e.g. HR's "requester's line
 * manager") in their provider's boot():
 *
 *   app(ApproverResolvers::class)->register(LineManagerResolver::class);
 *
 * resolve() returns candidate user ids; the approvals service removes the
 * requester (APR-07) and inactive users afterwards.
 */
interface ApproverResolver
{
    /** The `approver.type` value, e.g. `branch_manager`. */
    public function key(): string;

    /** Translation key of its name in the builder. */
    public function label(): string;

    /**
     * Settings the builder asks for, e.g. `[['name' => 'levels', 'type' => 'integer', 'min' => 1, 'max' => 5]]`.
     * Types: integer, role (uuid or `template:<key>`), user (uuid), field (a field name of the document type).
     *
     * @return list<array<string, mixed>>
     */
    public function params(): array;

    /**
     * Problems with the approver's settings for $type, translated.
     *
     * @param  array<string, mixed>  $approver
     * @return list<string>
     */
    public function validate(array $approver, DocumentType $type): array;

    /**
     * @param  array<string, mixed>  $approver
     * @return list<string> candidate user ids
     */
    public function resolve(array $approver, ApprovalSubject $subject): array;

    /**
     * Who the approver is, for people (inbox, escalation text): "Branch
     * manager", "Accountant", "Amina Otieno".
     *
     * @param  array<string, mixed>  $approver
     */
    public function describe(array $approver): string;
}
