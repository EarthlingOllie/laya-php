"""Question payloads shared by bin/laya-record-fixtures and bin/laya-measure.

One definition, so the latency figures in docs/PLAN.md always time the same requests the
fixtures record.
"""

TICKET = ("Hi, we were billed twice for March. Please refund the duplicate charge today "
          "or we will cancel our plan.")

DEPARTMENT = {
    "type": "choice",
    "instructions": "Which department should handle this?",
    "criteria": {"billing": "invoices, payments, refunds",
                 "technical": "bugs, outages, system errors",
                 "other": "everything else"},
}

URGENCY = {
    "type": "score",
    "instructions": "How urgent is this?",
    "criteria": ["can wait", "soon", "today", "blocking"],
}

CHURN = {"type": "noul", "instructions": "Does the customer threaten to cancel or leave?"}
