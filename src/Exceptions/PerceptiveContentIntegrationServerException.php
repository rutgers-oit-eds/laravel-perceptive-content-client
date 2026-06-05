<?php

namespace Rutgers\PerceptiveClient\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;

class PerceptiveContentIntegrationServerException extends Exception
{
    /**
     * Report the exception.
     *
     * @return void
     */
    public function report(LoggerInterface $logger)
    {
        $logger->error("There was an error communicating with the Perceptive Content Integration Server. Details: $this->message");
    }

    /**
     * Render the exception into an HTTP response.
     *
     * @param  Request  $request
     * @return Response
     */
    public function render($request)
    {
        return response('Error: PC Integration Server Exception!', 500);
    }
}
