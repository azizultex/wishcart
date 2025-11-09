import * as React from "react"
import * as SelectPrimitive from "@radix-ui/react-select"
import { Check, ChevronDown, X } from "lucide-react"
import { cn } from "@/lib/utils"

type Option = {
  value: string
  label: string
  type?: 'post' | 'page' | 'product'
}

const MultiSelect = React.forwardRef<
  HTMLDivElement,
  {
    options: Option[]
    selected: Option[]
    onChange: (selected: Option[]) => void
    placeholder?: string
    type?: 'post' | 'page' | 'product'
    disabled?: boolean
  }
>(({ options, selected, onChange, placeholder, type, disabled, ...props }, ref) => {
  const [isOpen, setIsOpen] = React.useState(false)

  const filteredOptions = type 
    ? options.filter(option => option.type === type)
    : options

  const handleSelect = (value: string) => {
    const item = filteredOptions.find(opt => opt.value === value)
    if (item) {
      if (selected.some(i => i.value === value)) {
        onChange(selected.filter(i => i.value !== value))
      } else {
        onChange([...selected, item])
      }
    }
    setIsOpen(true)
  }

  const handleUnselect = (item: Option) => {
    onChange(selected.filter((i) => i.value !== item.value))
  }

  return (
    <div ref={ref} className="relative w-full" {...props}>
      <div className="flex flex-wrap gap-1 p-2 border rounded-md min-h-[2.5rem]">
         {selected.map((item) => (
          <span key={item.value} className="inline-flex items-center bg-secondary text-secondary-foreground rounded-md px-2 py-1 text-sm">
            {item.label}
            <button
              type="button"
              onClick={() => handleUnselect(item)}
              className="ml-1 hover:bg-secondary-hover rounded-full p-0.5"
            >
              <X className="h-3 w-3" />
            </button>
          </span>
        ))}
        <SelectPrimitive.Root 
          open={isOpen}
          onOpenChange={setIsOpen}
          value={undefined}
          onValueChange={handleSelect}
          disabled={disabled}
        >
          <SelectPrimitive.Trigger className="inline-flex items-center justify-between flex-1 px-2 text-sm focus:outline-none cursor-pointer">
            <SelectPrimitive.Value placeholder={placeholder} />
            <ChevronDown className="h-4 w-4 opacity-50 ml-auto" />
          </SelectPrimitive.Trigger>
          <SelectPrimitive.Portal>
            <SelectPrimitive.Content className="relative z-50 max-h-60 min-w-[8rem] overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md animate-in fade-in-80">
              <SelectPrimitive.Viewport className="p-1">
                {filteredOptions.map((item) => (
                  <SelectPrimitive.Item
                    key={item.value}
                    value={item.value}
                    className="relative flex cursor-default select-none items-center rounded-sm py-1.5 pl-8 pr-2 text-sm outline-none focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50"
                  >
                    <span className="absolute left-2 flex h-3.5 w-3.5 items-center justify-center">
                      {selected.some(i => i.value === item.value) && (
                        <Check className="h-4 w-4" />
                      )}
                    </span>
                    <SelectPrimitive.ItemText>{item.label}</SelectPrimitive.ItemText>
                  </SelectPrimitive.Item>
                ))}
              </SelectPrimitive.Viewport>
            </SelectPrimitive.Content>
          </SelectPrimitive.Portal>
        </SelectPrimitive.Root>
      </div>
    </div>
  )
})
MultiSelect.displayName = "MultiSelect"

export { MultiSelect }