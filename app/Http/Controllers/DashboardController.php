<?php

namespace App\Http\Controllers;

use App\Formatters\DashboardFormatter;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /dashboard: what is waiting on you, where every live job stands, and the form that takes a material
 * list.
 *
 * It used to be a signpost that redirected at the projects board, then the upload form on its own, and
 * before either the fork in the road between the board and an onboarding page that asked a business to
 * email us example spreadsheets. This is where login, registration and email verification all land, so
 * the first screen of the day has to answer the two questions somebody arrives with - what needs doing,
 * and where is my job up to - as well as take the spreadsheet in their hand.
 *
 * It answers them without drawing the board. The three sections are a summary: counts, one line per
 * job, and a list of outstanding actions that each link into the board where the work is actually done
 * (the batch ones open its quotes/orders modal directly). Nothing here duplicates a control - the
 * clarification forms, the nest, the quote emails and the delivery record all stay in one place.
 *
 * See App\Formatters\DashboardFormatter for what the summary costs, which is deliberately little: this
 * page is on the critical path of every login.
 *
 * Two ways to upload, because a job's materials do not always arrive in one file on one day:
 *
 *  - A new project, which creates it and imports into it (ProjectController::store).
 *  - An existing project, which imports into one that has not been nested yet
 *    (ProductController::store).
 *
 * Both already existed, driven from the board's new-project modal. Nothing here replaces that modal
 * or the clarification steps behind it - a project needing a price book clarification is still
 * finished on the board, which is where it is drawn.
 *
 * A new project can also be handed to a colleague as it is created, because the person with the
 * spreadsheet is often not the person running the job - see User::colleagueOptions.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        /*
         * The summary half. Null for a user with no business - a hand-created account, or one whose
         * registration never matched a domain - and the page then draws nothing but the upload form,
         * rather than the whole application 500ing on the screen login lands on. See
         * HandleInertiaRequests::businessProp, which guards the same thing for the same reason.
         */
        $summary = $user->business
            ? (new DashboardFormatter)->summary($user->business, $user)
            : null;

        return Inertia::render('Dashboard', [
            'eligibleProjects' => $this->eligibleProjects($user->id),
            /*
             * The other staff a new project can be handed to as it is created - the draftsman
             * uploading for the manager running the job. See User::colleagueOptions.
             */
            'colleagues' => $user->colleagueOptions(),
            //Where the business's live work stands: four counts, one row per job
            'pipeline' => $summary['pipeline'] ?? [],
            'liveProjects' => $summary['projects'] ?? [],
            //What is waiting on somebody, most urgent first
            'actions' => $summary['actions'] ?? [],
        ]);
    }

    /**
     * The projects an upload is allowed to be added to.
     *
     * Deliberately the same three conditions as PrerequisiteConditions::uploadMaterials, which is
     * what ProductController::store actually enforces - yours, live, and nothing nested into a batch
     * yet. Offering anything wider would be offering a 403.
     *
     * "Yours" means a project you manage or one you uploaded for a colleague (Project::managedBy),
     * which is the same answer that gate gives. A job's materials do not arrive in one file on one
     * day, and the second file reaches the draftsman who sent the first - so leaving their
     * colleagues' projects out would leave them with no way to finish what they started.
     *
     * That set is the Nesting column's, minus colleagues' cards: the column holds exactly the
     * projects that exist and have not been batched. It is NOT Business::projectsReadyForBatching(),
     * which excludes a project still waiting on a price book clarification - those are drawn on the
     * board too (as unfinished imports) and are perfectly legal upload targets, so leaving them out
     * would hide a project the user can see from the only screen that can add to it. It is also far
     * cheaper: that method runs a price book match per material row of every project the business
     * has ever had, which is not a price worth paying to fill a dropdown.
     *
     * @return list<array{id: int, name: string, reference: string|null, rows: int, projectManagerName: string|null}>
     */
    private function eligibleProjects(int $userId): array
    {
        return Project::query()
            ->select(['id', 'name', 'reference', 'user_id'])
            ->with('user:id,name')
            ->withCount('rawMaterialQuotes')
            ->managedBy($userId)
            ->where('archive', false)
            //No piece of it is in a batch. Nested work is past the point where a BOM can grow.
            ->whereDoesntHave('pieces', fn (Builder $pieces) => $pieces->has('batch'))
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'reference' => $project->reference,
                //So the select can say what is already on a project before adding to it
                'rows' => $project->raw_material_quotes_count,
                /*
                 * Whose job it is, named only when it is not the uploader's own. The dropdown now
                 * mixes your own projects with the ones you uploaded for a colleague, and two jobs
                 * with similar names belonging to different managers is exactly the mix-up that puts
                 * one manager's steel on another's cutting list.
                 */
                'projectManagerName' => $project->user_id === $userId ? null : $project->user->name,
            ])
            ->all();
    }
}
