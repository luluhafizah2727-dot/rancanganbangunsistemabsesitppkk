export function BrandMark({ compact = false, inverse = false }: { compact?: boolean; inverse?: boolean }) {
  return (
    <div className={`brand ${inverse ? 'brand--inverse' : ''}`}>
      <span className="brand__mark" aria-hidden="true">
        <img src="/tp-pkk-logo.png" alt="" width={compact ? 34 : 42} height={compact ? 34 : 42} />
      </span>
      {!compact ? (
        <span className="brand__text">
          <small>Absensi</small>
          <strong>TP PKK Balangan</strong>
        </span>
      ) : null}
    </div>
  )
}
