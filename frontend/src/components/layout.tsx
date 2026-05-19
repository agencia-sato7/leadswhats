import type { ReactNode } from 'react';

function join(...values: Array<string | undefined>): string {
  return values.filter(Boolean).join(' ');
}

export function AppShell({ children }: { children: ReactNode }) {
  return <main className="lw-app-shell">{children}</main>;
}

export function Topbar({ left, right }: { left: ReactNode; right?: ReactNode }) {
  return (
    <header className="lw-topbar">
      <div>{left}</div>
      {right ? <div>{right}</div> : null}
    </header>
  );
}

export function Sidebar({ children }: { children: ReactNode }) {
  return <aside className="lw-sidebar">{children}</aside>;
}

export function PageHeader({ title, subtitle, actions }: { title: ReactNode; subtitle?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="lw-page-header">
      <div>
        <h1 className="lw-page-title">{title}</h1>
        {subtitle ? <p className="lw-page-subtitle">{subtitle}</p> : null}
      </div>
      {actions ? <div className="lw-page-actions">{actions}</div> : null}
    </div>
  );
}

export function Stack({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={join('lw-stack', className)}>{children}</div>;
}
