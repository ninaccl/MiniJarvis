<?php

namespace App\Task;

use App\Auth\AuthContext;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
final class TaskController
{
    private $tasks;
    public function __construct(TaskService $tasks)
    {
        $this->tasks = $tasks;
    }
    public function listItems(Request $request)
    {
        $assignee = $request->query('assignee_id');
        return Response::success($this->tasks->listItems($this->context($request), $request->query('status', 'all') !== null ? $request->query('status', 'all') : 'all', $assignee === null || $assignee === '' ? null : $this->positiveInt($assignee)));
    }
    public function create(Request $request)
    {
        return Response::success($this->tasks->create($this->context($request), $request->json()), 201);
    }
    public function get(Request $request)
    {
        return Response::success($this->tasks->get($this->context($request), $this->id($request)));
    }
    public function update(Request $request)
    {
        return Response::success($this->tasks->update($this->context($request), $this->id($request), $request->json()));
    }
    public function delete(Request $request)
    {
        $this->tasks->delete($this->context($request), $this->id($request));
        return Response::success(null);
    }
    private function id(Request $request)
    {
        return $this->positiveInt($request->routeParam('id'), true);
    }
    private function positiveInt($value, $notFound = false)
    {
        if ($value === null || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new ApiException($notFound ? 404 : 422, $notFound ? 'TASK_NOT_FOUND' : 'VALIDATION_FAILED', $notFound ? 'The task was not found.' : 'Query parameters are invalid.');
        }
        return (int) $value;
    }
    private function context(Request $request)
    {
        $context = $request->attribute('auth');
        if (!$context instanceof AuthContext) {
            throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'A valid Bearer token is required.');
        }
        return $context;
    }
}
