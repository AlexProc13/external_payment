<?php

function dataException($exception)
{
    return [
        'message' => $exception->getMessage(),
        'exception' => get_class($exception),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => collect($exception->getTrace())->map(function ($trace) {
            return \Arr::except($trace, ['args']);
        })->all(),
    ];
}

function shortDataException($exception)
{
    return [
        'message' => $exception->getMessage(),
        'exception' => get_class($exception),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
    ];
}

function apiFormatResponse($status, $data, $properties = [])
{
    $response = [
        'status' => $status,
        'data' => $data,
    ];

    foreach ($properties as $key => $item) {
        $response[$key] = $item;
    }

    return $response;
}
