package com.payhub.guest.ui.purchase

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.payhub.guest.ui.theme.CText
import com.payhub.guest.ui.theme.CText2
import com.payhub.guest.ui.theme.PhPrimary
import com.payhub.guest.ui.theme.PhPrimaryLight

@Composable
fun FieldLabel(text: String) {
    Text(text, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, color = CText2, modifier = Modifier.padding(bottom = 6.dp, top = 14.dp))
}

@Composable
fun <T> ChipRow(options: List<T>, labelOf: (T) -> String, selected: T?, onSelect: (T) -> Unit) {
    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        items(options) { opt ->
            val isSelected = opt == selected
            Box(
                modifier = Modifier
                    .background(if (isSelected) PhPrimary else PhPrimaryLight, RoundedCornerShape(999.dp))
                    .clickable { onSelect(opt) }
                    .padding(horizontal = 16.dp, vertical = 10.dp)
            ) {
                Text(labelOf(opt), color = if (isSelected) Color.White else PhPrimary, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

@Composable
fun AmountField(label: String, amountChips: List<Int>, amount: String, onAmountChange: (String) -> Unit) {
    FieldLabel(label)
    Text("₦${amount.ifBlank { "0" }}", fontWeight = FontWeight.ExtraBold, fontSize = 22.sp, color = CText, modifier = Modifier.padding(bottom = 10.dp))
    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        items(amountChips) { value ->
            val isSelected = amount == value.toString()
            Box(
                modifier = Modifier
                    .background(if (isSelected) PhPrimary else PhPrimaryLight, RoundedCornerShape(999.dp))
                    .clickable { onAmountChange(value.toString()) }
                    .padding(horizontal = 14.dp, vertical = 8.dp)
            ) {
                Text("₦$value", color = if (isSelected) Color.White else PhPrimary, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
            }
        }
    }
    OutlinedTextField(
        value = amount,
        onValueChange = { onAmountChange(it.filter { c -> c.isDigit() }) },
        placeholder = { Text("Or enter custom amount") },
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
        modifier = Modifier.fillMaxWidth().padding(top = 10.dp),
        singleLine = true,
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun <T> SimpleDropdown(
    label: String,
    options: List<T>,
    labelOf: (T) -> String,
    selected: T?,
    placeholder: String,
    onSelect: (T) -> Unit,
) {
    var expanded by remember { mutableStateOf(false) }
    FieldLabel(label)
    ExposedDropdownMenuBox(expanded = expanded, onExpandedChange = { expanded = it }) {
        OutlinedTextField(
            value = selected?.let(labelOf) ?: "",
            onValueChange = {},
            readOnly = true,
            placeholder = { Text(placeholder) },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded) },
            modifier = Modifier.fillMaxWidth().menuAnchor(),
        )
        ExposedDropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            options.forEach { opt ->
                DropdownMenuItem(text = { Text(labelOf(opt)) }, onClick = { onSelect(opt); expanded = false })
            }
        }
    }
}

@Composable
fun VerifyRow(
    value: String,
    onValueChange: (String) -> Unit,
    placeholder: String,
    verifying: Boolean,
    onVerify: () -> Unit,
) {
    Row(verticalAlignment = androidx.compose.ui.Alignment.CenterVertically) {
        OutlinedTextField(
            value = value,
            onValueChange = onValueChange,
            placeholder = { Text(placeholder) },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
            modifier = Modifier.weight(1f),
            singleLine = true,
        )
        androidx.compose.foundation.layout.Spacer(Modifier.padding(start = 8.dp))
        Box(
            modifier = Modifier
                .background(PhPrimaryLight, RoundedCornerShape(12.dp))
                .clickable(enabled = !verifying) { onVerify() }
                .padding(horizontal = 16.dp, vertical = 14.dp)
        ) {
            Text(if (verifying) "..." else "Verify", color = PhPrimary, fontWeight = FontWeight.Bold, fontSize = 13.sp)
        }
    }
}
