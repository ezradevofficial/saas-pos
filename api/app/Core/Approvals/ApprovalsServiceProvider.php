<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Console\ProcessApprovalTimersCommand;
use App\Core\Approvals\Resolvers\ApproverResolvers;
use App\Core\Approvals\Resolvers\BranchManagerResolver;
use App\Core\Approvals\Resolvers\CostCentreOwnerResolver;
use App\Core\Approvals\Resolvers\DepartmentHeadResolver;
use App\Core\Approvals\Resolvers\ManagerLevelsUpResolver;
use App\Core\Approvals\Resolvers\RoleResolver;
use App\Core\Approvals\Resolvers\StageRolesResolver;
use App\Core\Approvals\Resolvers\UserResolver;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Mail\MailActions;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Workflow\Handlers\ApprovalHandler;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * APR-01..APR-09: approvals on workflow approval nodes. Rebinds the
 * engine's ApprovalHandler to EngineApprovals, registers the core
 * approver types (modules add theirs on ApproverResolvers), the
 * notification event types, the approve-by-email links and the timer
 * command (scheduled in routes/console.php).
 *
 * Decisions recorded in this service:
 * - a rejection always needs a reason; an approval only when the node
 *   sets `require_reason` (which also turns bulk approval off);
 * - an approval node is decided only through approvals, never through the
 *   workflow move endpoint;
 * - no eligible approver (APR-07): the next level's manager (or the
 *   node's escalation target), else the request is blocked for an admin
 *   to reassign;
 * - escalated approvers decide the step alone; delegation is per
 *   assignment and never transitive;
 * - permissions: `core.approval.view_all` (oversight list) and
 *   `core.approval.reassign` (reassign pending items; also what makes a
 *   role a "manager" for manager_levels_up and next-level escalation).
 *   Owner and Admin hold both; Branch Manager holds reassign at its scope.
 */
class ApprovalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ApproverResolvers::class);
        $this->app->bind(ApprovalHandler::class, EngineApprovals::class);
    }

    public function boot(): void
    {
        $resolvers = $this->app->make(ApproverResolvers::class);

        foreach ([BranchManagerResolver::class, DepartmentHeadResolver::class, CostCentreOwnerResolver::class,
            ManagerLevelsUpResolver::class, RoleResolver::class, UserResolver::class] as $resolver) {
            $resolvers->register($resolver);
        }

        $resolvers->register(StageRolesResolver::class, listed: false);

        ApprovalNotices::register($this->app->make(EventTypes::class));
        RateLimiter::for(EmailApprovals::LIMITER, fn (Request $request) => Limit::perMinute(EmailApprovals::PER_MINUTE)->by('ip|'.$request->ip()));

        $mail = $this->app->make(MailActions::class);

        foreach (ApprovalNotices::ACTIONABLE as $event) {
            $mail->register($event, fn (NotificationDelivery $delivery) => $this->app->make(EmailApprovals::class)->links($delivery));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ProcessApprovalTimersCommand::class]);
        }
    }
}
