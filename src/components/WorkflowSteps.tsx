import { Check, Circle, Clock3 } from "lucide-react";
import Link from "next/link";
import { workflowSteps } from "@/data/workspace";

const statusIcon = {
  complete: Check,
  active: Clock3,
  pending: Circle,
};

export function WorkflowSteps() {
  return (
    <div className="grid gap-3 lg:grid-cols-3">
      {workflowSteps.map((step, index) => {
        const Icon = statusIcon[step.status as keyof typeof statusIcon];
        return (
          <article key={step.id} className="rounded-lg bg-white p-4 shadow-soft ring-1 ring-line transition hover:-translate-y-1 hover:ring-doubao/30">
            <div className="flex items-center justify-between gap-3">
              <span className="font-mono text-xs text-doubao">{String(index + 1).padStart(2, "0")}</span>
              <Icon className="size-4 text-doubao" />
            </div>
            <h3 className="mt-4 text-lg font-semibold text-ink">{step.title}</h3>
            <dl className="mt-4 grid gap-3 text-sm leading-6">
              <div>
                <dt className="text-xs uppercase tracking-[0.18em] text-ink/40">做什么</dt>
                <dd className="mt-1 text-ink/75">{step.input}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-[0.18em] text-ink/40">得到什么</dt>
                <dd className="mt-1 text-ink/90">{step.output}</dd>
              </div>
            </dl>
            <Link
              href={step.href}
              className="mt-4 inline-flex w-full items-center justify-center rounded-md bg-panel px-3 py-2 text-sm font-semibold text-ink/65 ring-1 ring-line transition hover:bg-doubao hover:text-paper hover:ring-doubao"
            >
              {step.action}
            </Link>
          </article>
        );
      })}
    </div>
  );
}
