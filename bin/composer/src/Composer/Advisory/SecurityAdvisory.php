<?php declare(strict_types=1);











namespace Composer\Advisory;

use Composer\Semver\Constraint\ConstraintInterface;
use DateTimeImmutable;

class SecurityAdvisory extends PartialSecurityAdvisory
{




public $title;





public $cve;





public $link;





public $reportedAt;





public $sources;





public $severity;




public function __construct(string $packageName, string $advisoryId, ConstraintInterface $affectedVersions, string $title, array $sources, DateTimeImmutable $reportedAt, ?string $cve = null, ?string $link = null, ?string $severity = null)
{
parent::__construct($packageName, $advisoryId, $affectedVersions);

$this->title = $title;
$this->sources = $sources;
$this->reportedAt = $reportedAt;
$this->cve = $cve;
$this->link = $link;
$this->severity = $severity;
}




public function toIgnoredAdvisory(?string $ignoreReason): IgnoredSecurityAdvisory
{
return new IgnoredSecurityAdvisory(
$this->packageName,
$this->advisoryId,
$this->affectedVersions,
$this->title,
$this->sources,
$this->reportedAt,
$this->cve,
$this->link,
$ignoreReason,
$this->severity
);
}




#[\ReturnTypeWillChange]
public function jsonSerialize()
{
$data = parent::jsonSerialize();
$data['reportedAt'] = $data['reportedAt']->format(DATE_RFC3339);

return $data;
}
}
