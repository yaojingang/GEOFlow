import { Badge } from "@/components/Badge";
import { SettingsClient } from "@/components/SettingsClient";
import { agentModes, settingsGroups } from "@/data/workspace";

export default function SettingsPage() {
  return (
    <div className="p-4 sm:p-6">
      <div className="rounded-lg bg-white p-6 shadow-panel ring-1 ring-line">
        <Badge tone="doubao">配置中心</Badge>
        <h1 className="mt-5 text-4xl font-semibold">域名、社媒、分析工具和 Agent 控制权</h1>
        <p className="mt-4 max-w-3xl text-ink/65 leading-7">
          如果没有配置，系统给出配置指导；如果已经配置，系统展示检测状态并判断是否需要新建。
        </p>
      </div>

      <section className="mt-6 grid gap-4 xl:grid-cols-2">
        {settingsGroups.map((group) => (
          <article key={group.title} className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
            <div className="flex items-center gap-3">
              <group.icon className="size-5 text-doubao" />
              <h2 className="text-xl font-semibold">{group.title}</h2>
            </div>
            <div className="mt-5 grid gap-3">
              {group.items.map(([name, status, guide]) => (
                <div key={name} className="grid gap-3 rounded-md bg-panel p-4 shadow-soft ring-1 ring-line md:grid-cols-[150px_120px_1fr]">
                  <p className="font-medium">{name}</p>
                  <p className="text-sm text-doubao">{status}</p>
                  <p className="text-sm leading-6 text-ink/58">{guide}</p>
                </div>
              ))}
            </div>
          </article>
        ))}
      </section>

      <section className="mt-6 rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
        <div className="flex flex-col justify-between gap-4 md:flex-row md:items-center">
          <div>
            <h2 className="text-2xl font-semibold">Agent 项目控制权限</h2>
            <p className="mt-2 text-sm text-ink/58">默认关闭。开启后仍按权限粒度和危险操作二次确认执行。</p>
          </div>
          <Badge>控制权限关闭</Badge>
        </div>

        <div className="mt-5 grid gap-4 lg:grid-cols-3">
          {agentModes.map((mode) => (
            <article key={mode.title} className="rounded-md bg-panel p-4 shadow-soft ring-1 ring-line">
              <h3 className="font-semibold">{mode.title}</h3>
              <p className="mt-2 text-sm leading-6 text-ink/58">{mode.body}</p>
            </article>
          ))}
        </div>

        <p className="mt-5 text-sm leading-6 text-ink/58">
          下方真实配置区会保存到项目数据库。开启控制模式后，Agent 和功能页才能执行采样、报告等写入动作。
        </p>
      </section>

      <SettingsClient />
    </div>
  );
}
