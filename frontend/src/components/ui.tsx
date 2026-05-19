import type { ReactNode, TableHTMLAttributes, TextareaHTMLAttributes, InputHTMLAttributes, SelectHTMLAttributes, ButtonHTMLAttributes } from 'react';

type BaseProps = {
  children?: ReactNode;
  className?: string;
};

function join(...values: Array<string | undefined>): string {
  return values.filter(Boolean).join(' ');
}

export function Section({ children, className }: BaseProps) {
  return <section className={join('lw-section', className)}>{children}</section>;
}

export function Card({ children, className }: BaseProps) {
  return <div className={join('lw-card', className)}>{children}</div>;
}

export function MetricCard({ label, value, hint }: { label: string; value: ReactNode; hint?: ReactNode }) {
  return (
    <Card className="lw-metric-card">
      <p className="lw-metric-label">{label}</p>
      <p className="lw-metric-value">{value}</p>
      {hint ? <p className="lw-metric-hint">{hint}</p> : null}
    </Card>
  );
}

export function Button({ className, children, ...props }: ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button className={join('lw-button', className)} {...props}>
      {children}
    </button>
  );
}

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return <input className={join('lw-input', className)} {...props} />;
}

export function Select({ className, children, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <select className={join('lw-select', className)} {...props}>
      {children}
    </select>
  );
}

export function Textarea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return <textarea className={join('lw-textarea', className)} {...props} />;
}

export function Badge({ children, variant = 'neutral' }: { children: ReactNode; variant?: 'neutral' | 'success' | 'warning' | 'danger' | 'info' }) {
  return <span className={join('lw-badge', `lw-badge--${variant}`)}>{children}</span>;
}

export function Alert({ children, variant = 'info' }: { children: ReactNode; variant?: 'info' | 'success' | 'warning' | 'danger' }) {
  return <div className={join('lw-alert', `lw-alert--${variant}`)}>{children}</div>;
}

export function EmptyState({ title, description }: { title: string; description?: string }) {
  return (
    <div className="lw-state">
      <p className="lw-state-title">{title}</p>
      {description ? <p className="lw-state-description">{description}</p> : null}
    </div>
  );
}

export function LoadingState({ message = 'Carregando...' }: { message?: string }) {
  return (
    <div className="lw-state">
      <p className="lw-state-title">{message}</p>
    </div>
  );
}

export function ErrorState({ message }: { message: string }) {
  return <Alert variant="danger">{message}</Alert>;
}

export function Table({ className, ...props }: TableHTMLAttributes<HTMLTableElement>) {
  return (
    <div className="lw-table-wrap">
      <table className={join('lw-table', className)} {...props} />
    </div>
  );
}

export function FormGroup({ label, children, hint }: { label?: ReactNode; children: ReactNode; hint?: ReactNode }) {
  return (
    <label className="lw-form-group">
      {label ? <span className="lw-form-label">{label}</span> : null}
      {children}
      {hint ? <small className="lw-form-hint">{hint}</small> : null}
    </label>
  );
}
