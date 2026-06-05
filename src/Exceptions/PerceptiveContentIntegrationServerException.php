<?php

namespace Rutgers\PerceptiveClient\Exceptions;

use Exception;
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
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function render($request)
    {
        return response('Error: PC Integration Server Exception!', 500);
    }
}
