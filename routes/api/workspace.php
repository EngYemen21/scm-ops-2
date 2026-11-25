<?php

use App\Workspace\Http\DocumentsController;
use App\Workspace\Http\ProjectsController;
use App\Workspace\Http\TasksController;
use App\Workspace\Http\TicketsController;
use App\Workspace\Http\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
 * «الدعم والمشاريع والمستندات» — /api/workspace (docs/odoo/WAVE3.md, P5): three tools under one page.
 *
 *   page.workspace   open the section: read what one may see, raise tickets, add tasks, upload where a folder allows
 *   ticket.manage    keep the help desk (teams, stages, types) and act on any ticket
 *   project.manage   create projects, keep the task tags, and act on any project
 *   doc.manage       keep the folders and the document tags, and act on any document
 *
 * Most routes carry no permission beyond page.workspace on purpose: what a caller may do is the right of the people
 * of the record in hand — the members of a ticket's team, the members of a project, whoever may write a folder — and
 * is decided by the service (TicketAccess, ProjectAccess, FolderAccess). A refusal there is the same audited 403.
 * A record that came from Odoo is read-only while the section follows Odoo (422 WORKSPACE_OWNED_BY_SOURCE); the
 * replies, notes and comments people write here are OPS's own and stay open.
 */
Route::prefix('workspace')->middleware('perm:page.workspace')->group(function () {
    // ── what the page shares
    Route::get('summary', [WorkspaceController::class, 'summary']);
    Route::get('lookups', [WorkspaceController::class, 'lookups']);
    Route::get('customers', [WorkspaceController::class, 'customers']);
    Route::get('records', [WorkspaceController::class, 'record']);
    // a file is opened by whoever may see what it hangs on (FilesService::authorize)
    Route::get('files/{id}', [WorkspaceController::class, 'file']);
    Route::post('files/{id}/remove', [WorkspaceController::class, 'removeFile']);

    // ── tickets
    Route::get('tickets/lookups', [TicketsController::class, 'lookups']);
    Route::get('tickets', [TicketsController::class, 'index']);
    Route::get('tickets/{id}', [TicketsController::class, 'show']);
    Route::post('tickets', [TicketsController::class, 'store']);
    Route::patch('tickets/{id}', [TicketsController::class, 'update']);
    Route::post('tickets/{id}/assign', [TicketsController::class, 'assign']);
    Route::post('tickets/{id}/move', [TicketsController::class, 'move']);
    Route::post('tickets/{id}/close', [TicketsController::class, 'close']);
    Route::post('tickets/{id}/reopen', [TicketsController::class, 'reopen']);
    Route::post('tickets/{id}/archive', [TicketsController::class, 'archive']);
    Route::post('tickets/{id}/restore', [TicketsController::class, 'restore']);
    Route::post('tickets/{id}/messages', [TicketsController::class, 'message']);
    Route::middleware('perm:ticket.manage')->group(function () {
        Route::post('ticket-teams', [TicketsController::class, 'storeTeam']);
        Route::patch('ticket-teams/{id}', [TicketsController::class, 'updateTeam']);
        Route::post('ticket-stages', [TicketsController::class, 'storeStage']);
        Route::patch('ticket-stages/{id}', [TicketsController::class, 'updateStage']);
        Route::post('ticket-types', [TicketsController::class, 'storeType']);
        Route::patch('ticket-types/{id}', [TicketsController::class, 'updateType']);
    });

    // ── projects
    Route::get('projects', [ProjectsController::class, 'index']);
    Route::get('projects/{id}', [ProjectsController::class, 'show']);
    Route::get('projects/{id}/board', [ProjectsController::class, 'board']);
    Route::post('projects', [ProjectsController::class, 'store'])->middleware('perm:project.manage');
    // the project's own manager, or the projects manager (ProjectsService::mustKeep)
    Route::patch('projects/{id}', [ProjectsController::class, 'update']);
    Route::put('projects/{id}/members', [ProjectsController::class, 'members']);
    Route::post('projects/{id}/status', [ProjectsController::class, 'status']);
    Route::post('projects/{id}/archive', [ProjectsController::class, 'archive']);
    Route::post('projects/{id}/restore', [ProjectsController::class, 'restore']);
    Route::post('projects/{id}/stages', [ProjectsController::class, 'storeStage']);
    Route::patch('task-stages/{id}', [ProjectsController::class, 'updateStage']);
    Route::post('task-tags', [ProjectsController::class, 'storeTag'])->middleware('perm:project.manage');
    Route::patch('task-tags/{id}', [ProjectsController::class, 'updateTag'])->middleware('perm:project.manage');

    // ── tasks
    Route::get('tasks/lookups', [TasksController::class, 'lookups']);
    Route::get('tasks', [TasksController::class, 'index']);
    Route::get('tasks/{id}', [TasksController::class, 'show']);
    Route::post('tasks', [TasksController::class, 'store']);
    Route::patch('tasks/{id}', [TasksController::class, 'update']);
    Route::post('tasks/{id}/move', [TasksController::class, 'move']);
    Route::post('tasks/{id}/done', [TasksController::class, 'done']);
    Route::post('tasks/{id}/reopen', [TasksController::class, 'reopen']);
    Route::post('tasks/{id}/cancel', [TasksController::class, 'cancel']);
    Route::post('tasks/{id}/archive', [TasksController::class, 'archive']);
    Route::post('tasks/{id}/restore', [TasksController::class, 'restore']);
    Route::post('tasks/{id}/comments', [TasksController::class, 'comment']);
    Route::post('tasks/{id}/checklist', [TasksController::class, 'checklistAdd']);
    Route::patch('tasks/{id}/checklist/{item}', [TasksController::class, 'checklistUpdate']);
    Route::post('tasks/{id}/checklist/{item}/remove', [TasksController::class, 'checklistRemove']);

    // ── documents
    Route::get('folders', [DocumentsController::class, 'folders']);
    Route::get('documents/lookups', [DocumentsController::class, 'lookups']);
    Route::get('documents', [DocumentsController::class, 'index']);
    Route::get('documents/{id}', [DocumentsController::class, 'show']);
    // whoever may write the folder (FolderAccess)
    Route::post('documents', [DocumentsController::class, 'store']);
    Route::patch('documents/{id}', [DocumentsController::class, 'update']);
    Route::post('documents/{id}/move', [DocumentsController::class, 'move']);
    Route::post('documents/{id}/versions', [DocumentsController::class, 'version']);
    Route::post('documents/{id}/archive', [DocumentsController::class, 'archive']);
    Route::post('documents/{id}/restore', [DocumentsController::class, 'restore']);
    Route::middleware('perm:doc.manage')->group(function () {
        Route::post('folders', [DocumentsController::class, 'storeFolder']);
        Route::patch('folders/{id}', [DocumentsController::class, 'updateFolder']);
        Route::post('folders/{id}/archive', [DocumentsController::class, 'archiveFolder']);
        Route::post('folders/{id}/restore', [DocumentsController::class, 'restoreFolder']);
        Route::post('doc-tags', [DocumentsController::class, 'storeTag']);
        Route::patch('doc-tags/{id}', [DocumentsController::class, 'updateTag']);
    });
});
