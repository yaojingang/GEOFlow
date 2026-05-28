import { clsx } from "clsx";

export function Badge({ children, tone = "doubao" }: { children: React.ReactNode; tone?: "doubao" | "dark" }) {
  return (
    <span
      className={clsx(
        "inline-flex items-center rounded-md px-2.5 py-1 text-xs font-medium ring-1",
        tone === "doubao" && "bg-doubao/10 text-doubao ring-doubao/35",
        tone === "dark" && "bg-ink/5 text-ink/70 ring-ink/10",
      )}
    >
      {children}
    </span>
  );
}
