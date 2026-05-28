import { generateReport, runDoubaoSampling } from "@/lib/doubao-service";
import { getWorkspaceState } from "@/lib/workspace-service";

export const monitorCadenceDays = [7, 14, 30];

export function resolveMonitorDay(requestedDay?: number) {
  if (requestedDay && monitorCadenceDays.includes(requestedDay)) {
    return requestedDay;
  }

  const start = new Date(process.env.MONITOR_START_DATE ?? "2026-05-27T00:00:00Z");
  const elapsedDays = Math.max(0, Math.floor((Date.now() - start.getTime()) / 86_400_000));
  return monitorCadenceDays.find((day) => elapsedDays <= day) ?? 30;
}

export async function runMonitorCycle(options: { day?: number; sampleLimit?: number }) {
  const day = resolveMonitorDay(options.day);
  const sampleLimit = Math.max(1, Math.min(options.sampleLimit ?? 5, 12));
  const before = await getWorkspaceState();
  const samples = await runDoubaoSampling(sampleLimit);
  const report = await generateReport(`Day ${day} 豆包 GEO 监测报告`);
  const after = await getWorkspaceState();

  return {
    day,
    sampleLimit,
    samplesWritten: samples.length,
    report: {
      title: report.title,
      slug: report.publicSlug,
      status: report.status,
    },
    before: before.stats,
    after: after.stats,
  };
}
