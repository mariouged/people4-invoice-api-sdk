"""
People4 Invoice API REST Client

Sends an invoice payload (JSON) to the People4 Peppol endpoint
and returns the XML response.

Usage:
    # uses doc/people4-invoice-example.json by default
    python people4_invoice_cli.py

    # or pass a custom payload file
    python people4_invoice_cli.py /path/to/other-invoice.json
"""

from __future__ import annotations

import json
import sys
import urllib.error
import urllib.request
from dataclasses import dataclass, field
from pathlib import Path
from typing import Optional
from xml.etree import ElementTree as ET

# ---------------------------------------------------------------------------
# Value objects
# ---------------------------------------------------------------------------


@dataclass(frozen=True)
class EndpointId:
    id: str
    scheme: str

    @classmethod
    def from_dict(cls, data: dict) -> "EndpointId":
        return cls(id=data["id"], scheme=data["scheme"])

    def to_dict(self) -> dict:
        return {"id": self.id, "scheme": self.scheme}


@dataclass(frozen=True)
class Address:
    street: str
    city: str
    postal_code: str
    country: str

    @classmethod
    def from_dict(cls, data: dict) -> "Address":
        return cls(
            street=data["street"],
            city=data["city"],
            postal_code=data["postalCode"],
            country=data["country"],
        )

    def to_dict(self) -> dict:
        return {
            "street": self.street,
            "city": self.city,
            "postalCode": self.postal_code,
            "country": self.country,
        }


@dataclass(frozen=True)
class Party:
    endpoint_id: EndpointId
    legal_name: str
    vat_id: str
    address: Address
    registration_id: Optional[str] = None

    @classmethod
    def from_dict(cls, data: dict) -> "Party":
        return cls(
            endpoint_id=EndpointId.from_dict(data["endpointId"]),
            legal_name=data["legalName"],
            vat_id=data["vatId"],
            address=Address.from_dict(data["address"]),
            registration_id=data.get("registrationId"),
        )

    def to_dict(self) -> dict:
        out: dict = {
            "endpointId": self.endpoint_id.to_dict(),
            "legalName": self.legal_name,
            "vatId": self.vat_id,
            "address": self.address.to_dict(),
        }
        if self.registration_id is not None:
            out["registrationId"] = self.registration_id
        return out


@dataclass(frozen=True)
class Tax:
    category: str
    percent: str

    @classmethod
    def from_dict(cls, data: dict) -> "Tax":
        return cls(category=data["category"], percent=data["percent"])

    def to_dict(self) -> dict:
        return {"category": self.category, "percent": self.percent}


@dataclass(frozen=True)
class InvoiceLine:
    id: str
    description: str
    quantity: str
    unit_code: str
    unit_price: str
    tax: Tax

    @classmethod
    def from_dict(cls, data: dict) -> "InvoiceLine":
        return cls(
            id=data["id"],
            description=data["description"],
            quantity=data["quantity"],
            unit_code=data["unitCode"],
            unit_price=data["unitPrice"],
            tax=Tax.from_dict(data["tax"]),
        )

    def to_dict(self) -> dict:
        return {
            "id": self.id,
            "description": self.description,
            "quantity": self.quantity,
            "unitCode": self.unit_code,
            "unitPrice": self.unit_price,
            "tax": self.tax.to_dict(),
        }


@dataclass(frozen=True)
class TaxTotal:
    category: str
    percent: str
    taxable_amount: str
    tax_amount: str

    @classmethod
    def from_dict(cls, data: dict) -> "TaxTotal":
        return cls(
            category=data["category"],
            percent=data["percent"],
            taxable_amount=data["taxableAmount"],
            tax_amount=data["taxAmount"],
        )

    def to_dict(self) -> dict:
        return {
            "category": self.category,
            "percent": self.percent,
            "taxableAmount": self.taxable_amount,
            "taxAmount": self.tax_amount,
        }


@dataclass(frozen=True)
class InvoiceTotals:
    line_extension: str
    tax_exclusive: str
    tax_inclusive: str
    payable: str

    @classmethod
    def from_dict(cls, data: dict) -> "InvoiceTotals":
        return cls(
            line_extension=data["lineExtension"],
            tax_exclusive=data["taxExclusive"],
            tax_inclusive=data["taxInclusive"],
            payable=data["payable"],
        )

    def to_dict(self) -> dict:
        return {
            "lineExtension": self.line_extension,
            "taxExclusive": self.tax_exclusive,
            "taxInclusive": self.tax_inclusive,
            "payable": self.payable,
        }


@dataclass(frozen=True)
class InvoiceHeader:
    number: str
    issue_date: str
    type_code: str
    currency: str
    buyer_reference: str
    order_reference: Optional[str] = None

    @classmethod
    def from_dict(cls, data: dict) -> "InvoiceHeader":
        return cls(
            number=data["number"],
            issue_date=data["issueDate"],
            type_code=data["typeCode"],
            currency=data["currency"],
            buyer_reference=data["buyerReference"],
            order_reference=data.get("orderReference"),
        )

    def to_dict(self) -> dict:
        out: dict = {
            "number": self.number,
            "issueDate": self.issue_date,
            "typeCode": self.type_code,
            "currency": self.currency,
            "buyerReference": self.buyer_reference,
        }
        if self.order_reference is not None:
            out["orderReference"] = self.order_reference
        return out


@dataclass(frozen=True)
class InvoicePayload:
    invoice: InvoiceHeader
    seller: Party
    buyer: Party
    lines: tuple[InvoiceLine, ...]
    tax_totals: tuple[TaxTotal, ...]
    totals: InvoiceTotals

    @classmethod
    def from_dict(cls, data: dict) -> "InvoicePayload":
        return cls(
            invoice=InvoiceHeader.from_dict(data["invoice"]),
            seller=Party.from_dict(data["seller"]),
            buyer=Party.from_dict(data["buyer"]),
            lines=tuple(InvoiceLine.from_dict(l) for l in data["lines"]),
            tax_totals=tuple(TaxTotal.from_dict(t) for t in data["taxTotals"]),
            totals=InvoiceTotals.from_dict(data["totals"]),
        )

    @classmethod
    def from_json_file(cls, file_path: str | Path) -> "InvoicePayload":
        path = Path(file_path)
        if not path.is_file():
            raise FileNotFoundError(f"Cannot read payload file: {path}")
        with path.open(encoding="utf-8") as fh:
            data = json.load(fh)
        return cls.from_dict(data)

    def to_dict(self) -> dict:
        return {
            "invoice": self.invoice.to_dict(),
            "seller": self.seller.to_dict(),
            "buyer": self.buyer.to_dict(),
            "lines": [line.to_dict() for line in self.lines],
            "taxTotals": [tt.to_dict() for tt in self.tax_totals],
            "totals": self.totals.to_dict(),
        }


# ---------------------------------------------------------------------------
# HTTP client
# ---------------------------------------------------------------------------


@dataclass
class People4ApiResponse:
    status_code: int
    body: str
    headers: dict[str, str]

    def is_success(self) -> bool:
        return 200 <= self.status_code < 300

    def to_xml(self) -> ET.Element:
        """Parse and return the response body as an ElementTree Element."""
        try:
            return ET.fromstring(self.body)
        except ET.ParseError as exc:
            raise ValueError(f"Response body is not valid XML: {self.body}") from exc

    def get_raw_xml(self) -> str:
        """Return the raw XML string."""
        return self.body


class People4InvoiceClient:
    DEFAULT_TIMEOUT: int = 30

    def __init__(self, base_url: str, timeout: int = DEFAULT_TIMEOUT) -> None:
        self._base_url = base_url
        self._timeout = timeout

    def submit_invoice(self, payload: InvoicePayload) -> People4ApiResponse:
        """POST the invoice payload and return the server response."""
        body_bytes = json.dumps(payload.to_dict(), ensure_ascii=False).encode("utf-8")

        request = urllib.request.Request(
            url=self._base_url,
            data=body_bytes,
            method="POST",
            headers={
                "Content-Type": "application/json",
                "Accept": "application/xml",
                "Content-Length": str(len(body_bytes)),
            },
        )

        try:
            with urllib.request.urlopen(request, timeout=self._timeout) as resp:
                status_code: int = resp.status
                response_body: str = resp.read().decode("utf-8")
                headers: dict[str, str] = dict(resp.headers)
        except urllib.error.HTTPError as exc:
            status_code = exc.code
            response_body = exc.read().decode("utf-8")
            headers = dict(exc.headers)

        return People4ApiResponse(
            status_code=status_code,
            body=response_body,
            headers=headers,
        )


# ---------------------------------------------------------------------------
# CLI entry point
# ---------------------------------------------------------------------------

_API_URL = "http://172.28.0.10:8080/peppol/v1"
_DEFAULT_PAYLOAD = Path(__file__).parent.parent / "doc" / "people4-invoice-example.json"


def main(argv: list[str]) -> int:
    payload_file = Path(argv[1]) if len(argv) > 1 else _DEFAULT_PAYLOAD

    try:
        print(f"Loading payload from: {payload_file}")
        payload = InvoicePayload.from_json_file(payload_file)

        client = People4InvoiceClient(_API_URL)

        print(f"Submitting invoice {payload.invoice.number} to {_API_URL}")
        response = client.submit_invoice(payload)

        print(f"HTTP Status: {response.status_code}")

        if not response.is_success():
            print(f"Error response ({response.status_code}):", file=sys.stderr)
            print(response.body, file=sys.stderr)
            return 1

        print("Response XML:")
        print(response.get_raw_xml())
        return 0

    except Exception as exc:  # noqa: BLE001
        print(f"Fatal: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
