<?php

namespace Fhp;

use Fhp\Protocol\BPD;
use Fhp\Protocol\Message;
use Fhp\Protocol\UnexpectedResponseException;
use Fhp\Protocol\UPD;
use Fhp\Segment\BaseSegment;
use Fhp\Segment\HIRMS\Rueckmeldungscode;
use Fhp\Segment\Paginateable;

/**
 * Represents actions that need to support pagination, this means that the bank can split the result into several
 * responses that have to be queried one by one via the pagination token.
 * To do this the request segments are stored and reused and potentially serialized, so the segments should
 * not contain sensitive data.
 */
abstract class PaginateableAction extends BaseAction
{
    /**
     * @var BaseSegment[] Stores the request created by BaseAction::getNextRequest to be reused in cases where the bank response
     * can be split over multiple pages e.g. request/response pairs. This avoids the need for {@link BPD} to be
     * available for paginated requests.
     */
    private $requestSegments;

    /**
     * If set, the last response from the server regarding this action indicated that there are more results to be
     * fetched using this pagination token. This is called "Aufsetzpunkt" in the specification.
     * @var string|null
     */
    private $paginationToken;

    /** {@inheritdoc} */
    public function serialize(): string
    {
        if (!$this->needsTan()) {
            throw new \RuntimeException('Cannot serialize this action, because it is not waiting for a TAN.');
        }
        return serialize([parent::serialize(), $this->paginationToken, $this->requestSegments]);
    }

    /** {@inheritdoc} */
    public function unserialize($serialized)
    {
        list($parentSerialized, $this->paginationToken, $this->requestSegments) = unserialize($serialized);
        parent::unserialize($parentSerialized);
    }

    /**
     * @return bool True if the response has not been read completely yet, i.e. additional requests to the server are
     *     necessary to continue reading the requested data.
     */
    public function hasMorePages(): bool
    {
        return !$this->isDone && $this->paginationToken !== null;
    }

    /** {@inheritdoc} */
    public function processResponse(Message $response)
    {
        if (($pagination = $response->findRueckmeldung(Rueckmeldungscode::PAGINATION)) !== null) {
            if (count($pagination->rueckmeldungsparameter) !== 1) {
                throw new UnexpectedResponseException("Unexpected pagination request: $pagination");
            }
            $this->paginationToken = $pagination->rueckmeldungsparameter[0];
        } else {
            parent::processResponse($response);
        }
    }

    /** {@inheritdoc} */
    public function getNextRequest(?BPD $bpd, ?UPD $upd)
    {
        if ($this->requestSegments === null) {
            $this->requestSegments = parent::getNextRequest($bpd, $upd);
        } elseif ($this->paginationToken !== null) {
            foreach ($this->requestSegments as $segment) {
                if ($segment instanceof Paginateable) {
                    $segment->setPaginationToken($this->paginationToken);
                }
            }
            $this->paginationToken = null;
        }
        return $this->requestSegments;
    }
}
